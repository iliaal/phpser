use ext_php_rs::prelude::*;
use ext_php_rs::types::{Zval, ZendHashTable, ZendStr};
use ext_php_rs::flags::DataType;
use ext_php_rs::binary::Binary;
use ext_php_rs::binary_slice::BinarySlice;
use ext_php_rs::boxed::ZBox;
use ext_php_rs::ffi::{ext_php_rs_zend_string_release, HashTable, zval};
use rkyv::{Archive, Deserialize, Serialize, rancor::Error as RkyvError};
use ahash::AHashMap;

// zend_hash_update isn't in ext-php-rs's allowed_bindings, so we declare it
// ourselves. Modern PHP exposes the function (not a macro) with this exact
// signature. Calling it directly lets us reuse a single zend_string* across
// many associative keys, which is what igbinary's compact_strings gives.
unsafe extern "C" {
    fn zend_hash_update(ht: *mut HashTable, key: *mut ZendStr, pData: *mut zval) -> *mut zval;
}

// Wire format: a front-loaded string dictionary + a value tree.
// All strings (Str variants, Object class, Object/Assoc keys) become u32
// indices into `dict`. The decode side caches the freshly allocated
// zend_string per slot and refcount-reuses it across references — the
// same architectural shape as igbinary's compact_strings.
#[derive(Archive, Deserialize, Serialize, Debug)]
#[rkyv(derive(Debug))]
pub struct PhpPayload {
    pub dict: Vec<Vec<u8>>,
    pub root: PhpValue,
}

// PhpValue is recursive (Packed/Assoc/Object hold more PhpValues), so the
// derive bounds need omit_bounds + explicit context bounds — otherwise the
// trait-elaborator overflows on the self-reference.
#[derive(Archive, Deserialize, Serialize, Debug)]
#[rkyv(
    bytecheck(bounds(__C: rkyv::validation::ArchiveContext)),
    serialize_bounds(__S: rkyv::ser::Writer + rkyv::ser::Allocator),
    deserialize_bounds(__D::Error: rkyv::rancor::Source),
    derive(Debug),
)]
pub enum PhpValue {
    Null,
    Bool(bool),
    Long(i64),
    Double(f64),
    Str(u32),
    Packed(#[rkyv(omit_bounds)] Vec<PhpValue>),
    Assoc(#[rkyv(omit_bounds)] Vec<(PhpKey, PhpValue)>),
    Object {
        class: u32,
        #[rkyv(omit_bounds)]
        props: Vec<(u32, PhpValue)>,
    },
}

#[derive(Archive, Deserialize, Serialize, Debug)]
#[rkyv(derive(Debug))]
pub enum PhpKey {
    Int(i64),
    Str(u32),
}

// -----------------------------------------------------------------------------
// Encode side — unchanged from V1 (string interning into a per-payload dict).
// -----------------------------------------------------------------------------

// Small dicts (~ <= 16 entries) hit linear scan; larger dicts switch to AHash.
// Linear scan wins on short keys + small N because it skips the hash work and
// stays in L1. AHash's SIMD-friendly mixing dominates once the table is big
// enough that linear probing becomes a measurable bottleneck.
const LINEAR_SCAN_THRESHOLD: usize = 16;

struct Interner {
    map: AHashMap<Vec<u8>, u32>,
    dict: Vec<Vec<u8>>,
}

impl Default for Interner {
    fn default() -> Self {
        Self { map: AHashMap::new(), dict: Vec::new() }
    }
}

impl Interner {
    #[inline]
    fn intern(&mut self, bytes: &[u8]) -> u32 {
        // Small-dict linear scan — fewer instructions per probe than hashing.
        if self.dict.len() <= LINEAR_SCAN_THRESHOLD {
            for (i, e) in self.dict.iter().enumerate() {
                if e.as_slice() == bytes {
                    return i as u32;
                }
            }
            // Crossing the threshold: backfill the map so future lookups go through it.
            let idx = self.dict.len() as u32;
            self.dict.push(bytes.to_vec());
            if self.dict.len() > LINEAR_SCAN_THRESHOLD {
                for (i, e) in self.dict.iter().enumerate() {
                    self.map.insert(e.clone(), i as u32);
                }
            }
            return idx;
        }
        if let Some(&idx) = self.map.get(bytes) {
            return idx;
        }
        let idx = self.dict.len() as u32;
        self.dict.push(bytes.to_vec());
        self.map.insert(self.dict[idx as usize].clone(), idx);
        idx
    }
}

fn zval_to_value(z: &Zval, ix: &mut Interner) -> PhpValue {
    match z.get_type() {
        DataType::Null | DataType::Undef => PhpValue::Null,
        DataType::False => PhpValue::Bool(false),
        DataType::True => PhpValue::Bool(true),
        DataType::Bool => PhpValue::Bool(z.bool().unwrap_or(false)),
        DataType::Long => PhpValue::Long(z.long().unwrap_or(0)),
        DataType::Double => PhpValue::Double(z.double().unwrap_or(0.0)),
        DataType::String => {
            let s = z.binary_slice().unwrap_or(&[]);
            PhpValue::Str(ix.intern(s))
        }
        DataType::Array => {
            if let Some(arr) = z.array() {
                hashtable_to_value(arr, ix)
            } else {
                PhpValue::Null
            }
        }
        DataType::Object(_) => {
            if let Some(obj) = z.object() {
                let class_idx = ix.intern(obj.get_class_name().unwrap_or_default().as_bytes());
                let mut props = Vec::new();
                if let Ok(ht) = obj.get_properties() {
                    for (k, v) in ht.iter() {
                        let key_idx = match k {
                            ext_php_rs::types::ArrayKey::String(s) => ix.intern(s.as_bytes()),
                            ext_php_rs::types::ArrayKey::Long(n) => ix.intern(n.to_string().as_bytes()),
                            ext_php_rs::types::ArrayKey::Str(s) => ix.intern(s.as_bytes()),
                        };
                        props.push((key_idx, zval_to_value(v, ix)));
                    }
                }
                PhpValue::Object { class: class_idx, props }
            } else {
                PhpValue::Null
            }
        }
        _ => PhpValue::Null,
    }
}

fn hashtable_to_value(ht: &ZendHashTable, ix: &mut Interner) -> PhpValue {
    let len = ht.len();
    let mut is_packed = true;
    let mut expected: i64 = 0;
    for (k, _) in ht.iter() {
        match k {
            ext_php_rs::types::ArrayKey::Long(n) if n == expected => expected += 1,
            _ => { is_packed = false; break; }
        }
    }
    if is_packed && len > 0 {
        let mut out = Vec::with_capacity(len);
        for (_, v) in ht.iter() {
            out.push(zval_to_value(v, ix));
        }
        PhpValue::Packed(out)
    } else {
        let mut out = Vec::with_capacity(len);
        for (k, v) in ht.iter() {
            let key = match k {
                ext_php_rs::types::ArrayKey::Long(n) => PhpKey::Int(n),
                ext_php_rs::types::ArrayKey::String(s) => PhpKey::Str(ix.intern(s.as_bytes())),
                ext_php_rs::types::ArrayKey::Str(s) => PhpKey::Str(ix.intern(s.as_bytes())),
            };
            out.push((key, zval_to_value(v, ix)));
        }
        PhpValue::Assoc(out)
    }
}

// -----------------------------------------------------------------------------
// Decode side. The StringCache holds *one* refcount per used dict entry for
// its lifetime; consumers (zvals via set_zend_string, hashtables via
// zend_hash_update) get their own. On drop, the cache releases its refcount,
// which lets PHP free the zend_string once all consumers are gone.
// -----------------------------------------------------------------------------

type ArchivedDict = rkyv::vec::ArchivedVec<rkyv::vec::ArchivedVec<u8>>;

struct StringCache<'a> {
    dict: &'a ArchivedDict,
    slots: Vec<*mut ZendStr>,
}

impl<'a> StringCache<'a> {
    fn new(dict: &'a ArchivedDict) -> Self {
        Self { dict, slots: vec![std::ptr::null_mut(); dict.len()] }
    }

    /// Returns the cached zend_string pointer for `idx`, allocating on first
    /// use. The cache owns one refcount on the returned pointer; callers that
    /// take ownership (e.g. set_zend_string) must bump first.
    fn ptr(&mut self, idx: u32) -> *mut ZendStr {
        let i = idx as usize;
        if i >= self.slots.len() {
            return std::ptr::null_mut();
        }
        if self.slots[i].is_null() {
            let bytes: &[u8] = self.dict[i].as_slice();
            let zb = ZendStr::new(bytes, false); // refcount = 1, owned by cache
            let r: &'static mut ZendStr = zb.into_raw();
            self.slots[i] = r as *mut ZendStr;
        }
        self.slots[i]
    }

    /// Convenience: produce a ZBox<ZendStr> the caller can consume via
    /// set_zend_string. Bumps the cached refcount by one to fund it.
    fn owned(&mut self, idx: u32) -> ZBox<ZendStr> {
        let ptr = self.ptr(idx);
        // SAFETY: ptr was just allocated or previously cached; non-interned
        // per-request string, refcount mutation is single-threaded under PHP.
        unsafe {
            (*ptr).gc.refcount += 1;
            ZBox::from_raw(ptr)
        }
    }
}

impl Drop for StringCache<'_> {
    fn drop(&mut self) {
        for &p in &self.slots {
            if !p.is_null() {
                // SAFETY: each non-null slot was created with ZendStr::new and
                // never released by us — we hand out fresh refcounts to
                // consumers but never decrement our own until now.
                unsafe { ext_php_rs_zend_string_release(p) };
            }
        }
    }
}

fn archived_to_zval(av: &ArchivedPhpValue, cache: &mut StringCache) -> Zval {
    let mut zv = Zval::new();
    match av {
        ArchivedPhpValue::Null => zv.set_null(),
        ArchivedPhpValue::Bool(b) => zv.set_bool(*b),
        ArchivedPhpValue::Long(n) => zv.set_long(n.to_native()),
        ArchivedPhpValue::Double(d) => zv.set_double(d.to_native()),
        ArchivedPhpValue::Str(idx) => {
            zv.set_zend_string(cache.owned(idx.to_native()));
        }
        ArchivedPhpValue::Packed(items) => {
            let mut ht = ZendHashTable::with_capacity(items.len() as u32);
            for item in items.iter() {
                let v = archived_to_zval(item, cache);
                let _ = ht.push(v);
            }
            let _ = zv.set_hashtable(ht);
        }
        ArchivedPhpValue::Assoc(pairs) => {
            let mut ht = ZendHashTable::with_capacity(pairs.len() as u32);
            for pair in pairs.iter() {
                let mut v = archived_to_zval(&pair.1, cache);
                match &pair.0 {
                    ArchivedPhpKey::Int(n) => {
                        let _ = ht.insert_at_index(n.to_native(), v);
                    }
                    ArchivedPhpKey::Str(idx) => {
                        let key_ptr = cache.ptr(idx.to_native());
                        // SAFETY: key_ptr non-null (idx validated by rkyv access),
                        // zend_hash_update addrefs the key internally for the HT.
                        // We pass &mut v as zval*; zend_hash_update does
                        // ZVAL_COPY_VALUE which transfers value ownership.
                        unsafe {
                            zend_hash_update(
                                &mut *ht as *mut ZendHashTable as *mut HashTable,
                                key_ptr,
                                &mut v as *mut Zval as *mut zval,
                            );
                        }
                        std::mem::forget(v);
                    }
                }
            }
            let _ = zv.set_hashtable(ht);
        }
        ArchivedPhpValue::Object { class: _class, props } => {
            // Object reconstruction is still a stub — round-trip as assoc array.
            let mut ht = ZendHashTable::with_capacity(props.len() as u32);
            for pair in props.iter() {
                let mut v = archived_to_zval(&pair.1, cache);
                let key_ptr = cache.ptr(pair.0.to_native());
                unsafe {
                    zend_hash_update(
                        &mut *ht as *mut ZendHashTable as *mut HashTable,
                        key_ptr,
                        &mut v as *mut Zval as *mut zval,
                    );
                }
                std::mem::forget(v);
            }
            let _ = zv.set_hashtable(ht);
        }
    }
    zv
}

// -----------------------------------------------------------------------------
// Public PHP functions
// -----------------------------------------------------------------------------

#[php_function]
pub fn rkyv_serialize(value: &Zval) -> PhpResult<Binary<u8>> {
    let mut ix = Interner::default();
    let root = zval_to_value(value, &mut ix);
    let payload = PhpPayload { dict: ix.dict, root };
    rkyv::to_bytes::<RkyvError>(&payload)
        .map(|b| Binary::new(b.to_vec()))
        .map_err(|e| PhpException::default(format!("rkyv serialize failed: {e}")))
}

#[php_function]
pub fn rkyv_unserialize(bytes: BinarySlice<u8>) -> PhpResult<Zval> {
    let archived = rkyv::access::<ArchivedPhpPayload, RkyvError>(*bytes)
        .map_err(|e| PhpException::default(format!("rkyv validate failed: {e}")))?;
    let mut cache = StringCache::new(&archived.dict);
    Ok(archived_to_zval(&archived.root, &mut cache))
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .function(wrap_function!(rkyv_serialize))
        .function(wrap_function!(rkyv_unserialize))
}
