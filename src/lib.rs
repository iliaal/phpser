use ext_php_rs::prelude::*;
use ext_php_rs::types::{Zval, ZendHashTable, ZendStr};
use ext_php_rs::binary::Binary;
use ext_php_rs::binary_slice::BinarySlice;
use ext_php_rs::boxed::ZBox;
use ext_php_rs::ffi::{
    ext_php_rs_zend_string_release, zval, zend_string, Bucket, HashTable,
    IS_UNDEF, IS_NULL, IS_FALSE, IS_TRUE, IS_LONG, IS_DOUBLE, IS_STRING, IS_ARRAY, IS_OBJECT,
};
use rkyv::{Archive, Deserialize, Serialize, rancor::Error as RkyvError};
use ahash::AHashMap;

// HT_IS_PACKED: bit set in the HashTable flags byte when the array has only
// sequential int keys 0..N-1. Lets us skip the O(N) iteration scan we used
// to do in V2 just to detect this case.
const HASH_FLAG_PACKED: u8 = 1 << 2;

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
    // Pointer-keyed cache for the common case where PHP hands us the same
    // zend_string (interned literals, repeated bucket keys). Pointer equality
    // is two cycles vs hashing dozens of key bytes — and this hit rate is
    // extremely high for rowset shapes.
    ptr_map: AHashMap<usize, u32>,
    dict: Vec<Vec<u8>>,
}

impl Default for Interner {
    fn default() -> Self {
        Self { map: AHashMap::new(), ptr_map: AHashMap::new(), dict: Vec::new() }
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

    /// Pointer-first intern. Reads the zend_string bytes only on a miss.
    /// SAFETY: `zs` must be a valid, non-null *mut zend_string.
    #[inline]
    unsafe fn intern_zs(&mut self, zs: *mut zend_string) -> u32 {
        let key = zs as usize;
        if let Some(&idx) = self.ptr_map.get(&key) {
            return idx;
        }
        let len = unsafe { (*zs).len };
        let ptr = unsafe { (*zs).val.as_ptr() as *const u8 };
        let bytes = unsafe { std::slice::from_raw_parts(ptr, len) };
        let idx = self.intern(bytes);
        self.ptr_map.insert(key, idx);
        idx
    }
}

/// SAFETY: `z` must be a valid zval pointer (alive, well-aligned).
unsafe fn zval_raw(z: *const zval, ix: &mut Interner) -> PhpValue {
    let ty = unsafe { (*z).u1.v.type_ } as u32;
    match ty {
        IS_UNDEF | IS_NULL => PhpValue::Null,
        IS_FALSE => PhpValue::Bool(false),
        IS_TRUE => PhpValue::Bool(true),
        IS_LONG => PhpValue::Long(unsafe { (*z).value.lval }),
        IS_DOUBLE => PhpValue::Double(unsafe { (*z).value.dval }),
        IS_STRING => {
            let zs = unsafe { (*z).value.str_ };
            PhpValue::Str(unsafe { ix.intern_zs(zs) })
        }
        IS_ARRAY => {
            let ht = unsafe { (*z).value.arr };
            unsafe { hashtable_to_value_raw(ht, ix) }
        }
        IS_OBJECT => {
            // Object path stays on ext-php-rs's higher-level wrapper — properties
            // touch handlers, lazy init, typed-prop guards. Not a hot path for
            // cache shapes (usually arrays/scalars).
            let zr: &Zval = unsafe { &*(z as *const Zval) };
            object_to_value(zr, ix)
        }
        _ => PhpValue::Null,
    }
}

fn zval_to_value(z: &Zval, ix: &mut Interner) -> PhpValue {
    // SAFETY: z came from PHP and is alive for the duration of this call.
    unsafe { zval_raw(z as *const Zval as *const zval, ix) }
}

fn object_to_value(z: &Zval, ix: &mut Interner) -> PhpValue {
    if let Some(obj) = z.object() {
        let class_idx = ix.intern(obj.get_class_name().unwrap_or_default().as_bytes());
        let mut props = Vec::new();
        if let Ok(ht) = obj.get_properties() {
            // Reuse the raw-FFI path for the property bag — it's a HashTable.
            let ht_ptr = ht as *const ZendHashTable as *mut HashTable;
            unsafe { ht_buckets_each(ht_ptr, |bkey, val| {
                let key_idx = match bkey {
                    BucketKey::Str(zs) => ix.intern_zs(zs),
                    BucketKey::Long(n) => ix.intern(n.to_string().as_bytes()),
                };
                props.push((key_idx, zval_raw(val, ix)));
            }) };
        }
        PhpValue::Object { class: class_idx, props }
    } else {
        PhpValue::Null
    }
}

enum BucketKey {
    Str(*mut zend_string),
    Long(i64),
}

/// Iterate the live buckets of a HashTable, skipping IS_UNDEF tombstones.
/// SAFETY: `ht` must be a valid HashTable pointer.
#[inline]
unsafe fn ht_buckets_each<F: FnMut(BucketKey, *mut zval)>(ht: *mut HashTable, mut f: F) {
    let n_used = unsafe { (*ht).nNumUsed };
    let ar_data: *mut Bucket = unsafe { (*ht).__bindgen_anon_1.arData };
    for i in 0..n_used {
        let b: *mut Bucket = unsafe { ar_data.add(i as usize) };
        let val_ptr: *mut zval = unsafe { &raw mut (*b).val };
        let ty = unsafe { (*val_ptr).u1.v.type_ } as u32;
        if ty == IS_UNDEF {
            continue;
        }
        let key = unsafe { (*b).key };
        let bkey = if key.is_null() {
            BucketKey::Long(unsafe { (*b).h } as i64)
        } else {
            BucketKey::Str(key)
        };
        f(bkey, val_ptr);
    }
}

/// SAFETY: `ht` must be a valid HashTable pointer.
unsafe fn hashtable_to_value_raw(ht: *mut HashTable, ix: &mut Interner) -> PhpValue {
    let n_used = unsafe { (*ht).nNumUsed };
    let n_elems = unsafe { (*ht).nNumOfElements };
    let flags = unsafe { (*ht).u.v.flags };
    let is_packed_flag = (flags & HASH_FLAG_PACKED) != 0;

    // PHP 8+ stores packed arrays as a flat zval[] at arPacked (union-aliased
    // with arData). Stride is sizeof(zval)=16, not sizeof(Bucket)=32.
    //
    // n_used > n_elems means holes in the middle (post-unset). PHP still flags
    // the array as packed, but the original indices have to be preserved on
    // round-trip — falling back to Assoc here keeps the keys intact.
    if is_packed_flag {
        let ar_packed: *mut zval = unsafe { (*ht).__bindgen_anon_1.arData as *mut zval };
        if n_used == n_elems {
            // Dense packed — pure stream.
            let mut out = Vec::with_capacity(n_elems as usize);
            for i in 0..n_used {
                let val_ptr: *mut zval = unsafe { ar_packed.add(i as usize) };
                out.push(unsafe { zval_raw(val_ptr, ix) });
            }
            return PhpValue::Packed(out);
        }
        // Sparse packed — preserve original int keys via Assoc.
        let mut out = Vec::with_capacity(n_elems as usize);
        for i in 0..n_used {
            let val_ptr: *mut zval = unsafe { ar_packed.add(i as usize) };
            let ty = unsafe { (*val_ptr).u1.v.type_ } as u32;
            if ty == IS_UNDEF { continue; }
            out.push((PhpKey::Int(i as i64), unsafe { zval_raw(val_ptr, ix) }));
        }
        return PhpValue::Assoc(out);
    }

    // Non-packed (assoc) path. Walk Buckets; some may still have small
    // sequential int keys without the packed flag — emit Packed in that case
    // so the decode side benefits.
    let mut out = Vec::with_capacity(n_elems as usize);
    let mut seen_assoc = false;
    let mut seq_next: i64 = 0;
    let mut packed_candidate: Vec<PhpValue> = Vec::with_capacity(n_elems as usize);
    unsafe {
        ht_buckets_each(ht, |bkey, val| {
            if !seen_assoc {
                if let BucketKey::Long(n) = bkey {
                    if n == seq_next {
                        seq_next += 1;
                        packed_candidate.push(zval_raw(val, ix));
                        return;
                    }
                }
                seen_assoc = true;
                for (i, v) in std::mem::take(&mut packed_candidate).into_iter().enumerate() {
                    out.push((PhpKey::Int(i as i64), v));
                }
            }
            let key = match bkey {
                BucketKey::Long(n) => PhpKey::Int(n),
                BucketKey::Str(zs) => PhpKey::Str(ix.intern_zs(zs)),
            };
            out.push((key, zval_raw(val, ix)));
        });
    }
    if !seen_assoc {
        PhpValue::Packed(packed_candidate)
    } else {
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
