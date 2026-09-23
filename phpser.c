/*
  +----------------------------------------------------------------------+
  | Copyright (c) 2025-2026, Ilia Alshanetsky                            |
  | Copyright (c) 2025-2026, Advanced Internet Designs Inc.              |
  +----------------------------------------------------------------------+
  | This source file is subject to the BSD 3-Clause license that is      |
  | bundled with this package in the file LICENSE.                       |
  +----------------------------------------------------------------------+
  | Author: Ilia Alshanetsky <ilia@ilia.ws>                              |
  +----------------------------------------------------------------------+
*/

#include "php.h"
#include "php_phpser.h"
#include "phpser_int.h"
#include "Zend/zend_API.h"
#include "Zend/zend_smart_str.h"
#include "Zend/zend_hash.h"

#include "Zend/zend_enum.h"
#include "Zend/zend_exceptions.h"
#include "Zend/zend_execute.h"          /* zend_verify_prop_assignable_by_ref */
#include "Zend/zend_objects_API.h"      /* zend_get_typed_property_info_for_slot */
#include "ext/standard/php_incomplete_class.h"
#if PHP_VERSION_ID >= 80400
# include "Zend/zend_lazy_objects.h"     /* zend_object_is_lazy, for the property-slot fast path */
#endif

#include <stdint.h>
#include <string.h>

/* PHP < 8.4 lacks this alias for classes object_init_ex cannot instantiate. */
#ifndef ZEND_ACC_UNINSTANTIABLE
# define ZEND_ACC_UNINSTANTIABLE \
    (ZEND_ACC_INTERFACE | ZEND_ACC_TRAIT | ZEND_ACC_IMPLICIT_ABSTRACT_CLASS | \
     ZEND_ACC_EXPLICIT_ABSTRACT_CLASS | ZEND_ACC_ENUM)
#endif

/* PHP 8.2 compatibility: use the PHP 8.3 GC_DTOR expansion. */
#if PHP_VERSION_ID < 80300
# define GC_DTOR(p) \
	do { \
		zend_refcounted_h *_p = &(p)->gc; \
		if (zend_gc_delref(_p) == 0) { \
			rc_dtor_func((zend_refcounted *)_p); \
		} else { \
			gc_check_possible_root((zend_refcounted *)_p); \
		} \
	} while (0)
#endif

/* Wire format version. Bump on any incompatible change. v2 adds optional
 * container tags (0x12-0x17: TAG_OBJECT_SLOTS, TAG_ASSOC_DICT, TAG_ROWSET,
 * TAG_TABLE, TAG_PACKED_DELTA, TAG_PACKED_AFFINE); decoders accept both
 * bytes. */
#define PHPSER_VERSION   0x01
#define PHPSER_VERSION_V2 0x02

#define TAG_NULL            0x00
#define TAG_FALSE           0x01
#define TAG_TRUE            0x02
#define TAG_LONG            0x03   /* zigzag varint */
#define TAG_DOUBLE          0x04   /* 8 bytes LE */
#define TAG_STR_DICT        0x05   /* varint dict_idx */
#define TAG_ASSOC           0x06   /* varint(len), N×(key, val) */
#define TAG_PACKED_MIXED    0x07   /* varint(len), N×val */
#define TAG_PACKED_LONGS    0x08   /* varint(len), N×zigzag-varint */
#define TAG_PACKED_DOUBLES  0x09   /* varint(len), N×8-byte LE */
#define TAG_OBJECT          0x0a   /* varint(class_idx), varint(nprops), N×(key_idx, val) */
#define TAG_PACKED_STRINGS  0x0b   /* varint(len), N×varint(dict_idx); typed string run */
#define TAG_STR_INLINE      0x0c   /* varint(len), bytes; single-use string, skips dict */
#define TAG_ENUM            0x0d   /* varint(class_idx), varint(case_name_idx) */
#define TAG_OBJECT_MAGIC    0x0e   /* varint(class_idx), value; class with __serialize/__unserialize.
                                      The value is the array __serialize() returned. */
#define TAG_OBJECT_LEGACY   0x0f   /* varint(class_idx), varint(len), bytes; class with the C-level
                                      ce->serialize / ce->unserialize hook (Serializable and SPL
                                      classes without __serialize). The bytes are opaque
                                      ce->serialize output. */
#define TAG_REF             0x10   /* varint(id); back-reference to a previously-emitted container.
                                      id counts in encounter order on both sides; tags TAG_OBJECT,
                                      TAG_OBJECT_SLOTS, TAG_OBJECT_MAGIC, TAG_OBJECT_LEGACY, TAG_ENUM,
                                      and TAG_NEW_REF each implicitly claim the next id. */
#define TAG_NEW_REF         0x11   /* inner value follows; claims the next id for an IS_REFERENCE wrap. */
#define TAG_OBJECT_SLOTS    0x12   /* varint(class_idx), varint(nprops), N×val; declared-property
                                      values only, in ce->properties_info_table order (wire v2). */
#define TAG_ASSOC_DICT      0x13   /* varint(n), N×varint(dict_key_idx), N×val; assoc whose keys are
                                      all dict-bound string refs (wire v2). */
#define TAG_ROWSET          0x14   /* varint(nrows), varint(ncols), N×varint(dict_key_idx),
                                      nrows×ncols×val; packed array of homogeneous assoc rows (wire
                                      v2). Emits the column schema once; each row is values only. */
#define TAG_TABLE           0x15   /* varint(nrows), varint(ncols), N×varint(dict_key_idx),
                                      ncols×(col_tag, col_payload); columnar rowset (wire v2).
                                      col_tag is PACKED_LONGS/DOUBLES/STRINGS/MIXED/DELTA; row
                                      count is implicit in the table header (no per-column len
                                      varint). */
#define TAG_PACKED_DELTA    0x16   /* varint(len), zigzag(v0), (len-1)×zigzag(delta); integer run
                                      stored as consecutive differences (wire v2). Arithmetic is
                                      wrapping mod 2^64 on both sides, so any int64 sequence
                                      reconstructs exactly and no overflow checks are needed.
                                      Standalone or as a TAG_TABLE column (column form omits the
                                      len varint like every other column tag). */
#define TAG_PACKED_AFFINE   0x17   /* varint(len), zigzag(base), zigzag(step); integer run where
                                      v[i] = base + i*step, mod 2^64 (wire v2). Constant runs are
                                      step 0. O(1) wire bytes for O(len) decoded data, which
                                      breaks the decoder's bytes-bound-memory invariant, so both
                                      sides enforce the shared PHPSER_SUBLINEAR_MAX_ELEMS budget
                                      and the tag is valid ONLY standalone: as a table column it
                                      would defeat the nrows<=remaining/ncols row-allocation
                                      bound (a few wire bytes claiming millions of row HTs). */

/* Assoc key tags (one byte before the key's payload). */
#define KEY_LONG        0x00
#define KEY_STR         0x01   /* varint(dict_idx) */
#define KEY_STR_INLINE  0x02   /* varint(len), bytes */

/* -------------------------------------------------------------------------
 * Varint helpers. Unsigned varint (LEB128) for lengths/indices, zigzag for
 * signed longs. Reader returns 0 on success, -1 on truncation.
 * ------------------------------------------------------------------------- */

/* A u64 varint is at most 10 bytes (ceil(64/7)); a tag + varint at most 11. */
#define VARINT_MAX_BYTES 10
/* Worst-case varint bytes for a value known to fit uint32 (ceil(32/7)). Used
 * only for pre-size estimates of dict-length / dict-index runs, which the
 * decoder caps at UINT32_MAX. */
#define VARINT_MAX_BYTES_U32 5

/* Callers must reserve worst-case capacity; these writers do not bounds-check. */
static zend_always_inline size_t varint_put(char *base, size_t pos, uint64_t v) {
    while (v >= 0x80) { base[pos++] = (char)((v & 0x7f) | 0x80); v >>= 7; }
    base[pos++] = (char)v;
    return pos;
}
static zend_always_inline size_t le64_put(char *base, size_t pos, double dv) {
#ifdef WORDS_BIGENDIAN
    uint64_t bits;
    memcpy(&bits, &dv, 8);
    for (int k = 0; k < 8; k++) base[pos++] = (char)((bits >> (k * 8)) & 0xff);
#else
    memcpy(base + pos, &dv, 8);
    pos += 8;
#endif
    return pos;
}

/* Reserve once to avoid smart_str_appendc's capacity check on every byte. */
static inline void varint_write_u64(smart_str *s, uint64_t v) {
    smart_str_alloc(s, VARINT_MAX_BYTES, 0);
    ZSTR_LEN(s->s) = varint_put(ZSTR_VAL(s->s), ZSTR_LEN(s->s), v);
}

static inline void emit_tag_and_varint(smart_str *s, uint8_t tag, uint64_t v) {
    smart_str_alloc(s, 1 + VARINT_MAX_BYTES, 0);
    char *base = ZSTR_VAL(s->s);
    size_t pos = ZSTR_LEN(s->s);
    base[pos++] = (char)tag;
    ZSTR_LEN(s->s) = varint_put(base, pos, v);
}

static inline int varint_read_u64(const uint8_t *buf, size_t buflen, size_t *pos, uint64_t *out) {
    /* Dict indices and row widths commonly fit in one byte. */
    size_t p = *pos;
    if (UNEXPECTED(p >= buflen)) return -1;
    uint8_t b = buf[p];
    if (EXPECTED((b & 0x80) == 0)) {
        *out = b;
        *pos = p + 1;
        return 0;
    }
    uint64_t v = b & 0x7f;
    p++;
    int shift = 7;
    while (p < buflen) {
        b = buf[p++];
        if (shift >= 64) return -1;
        uint64_t chunk = b & 0x7f;
        /* Only bit 0 fits in the tenth byte; higher bits would alias valid indices. */
        if (UNEXPECTED(shift == 63 && chunk > 1)) return -1;
        v |= chunk << shift;
        if ((b & 0x80) == 0) {
            *out = v;
            *pos = p;
            return 0;
        }
        shift += 7;
    }
    return -1;
}

static inline uint64_t zigzag_encode64(int64_t v) {
    return ((uint64_t)v << 1) ^ ((uint64_t)(v >> 63));
}

/* Wire length of an unsigned varint. Loop instead of clz builtins so the
 * MSVC (config.w32) build needs no intrinsic shims; the sizing passes that
 * call this are cold relative to the emit loops. */
static zend_always_inline uint32_t varint_len_u64(uint64_t v) {
    uint32_t n = 1;
    while (v >= 0x80) { v >>= 7; n++; }
    return n;
}

static inline int64_t zigzag_decode64(uint64_t v) {
    return (int64_t)((v >> 1) ^ (~(v & 1) + 1));
}

static inline void varint_write_i64(smart_str *s, int64_t v) {
    varint_write_u64(s, zigzag_encode64(v));
}

static inline int varint_read_i64(const uint8_t *buf, size_t buflen, size_t *pos, int64_t *out) {
    uint64_t u;
    if (varint_read_u64(buf, buflen, pos, &u) < 0) return -1;
    *out = zigzag_decode64(u);
    return 0;
}

/* Wire doubles are 8-byte little-endian IEEE754, independent of host order. */
static inline void smart_str_append_le64(smart_str *s, double v) {
    smart_str_alloc(s, 8, 0);
    ZSTR_LEN(s->s) = le64_put(ZSTR_VAL(s->s), ZSTR_LEN(s->s), v);
}

static inline double le64_read(const uint8_t *src) {
#ifdef WORDS_BIGENDIAN
    uint64_t bits = 0;
    for (int i = 0; i < 8; i++) bits |= ((uint64_t)src[i]) << (i * 8);
    double v;
    memcpy(&v, &bits, 8);
    return v;
#else
    double v;
    memcpy(&v, src, 8);
    return v;
#endif
}

/* The pointer-keyed icache never evicts; hash_map catches equal contents at
 * different addresses. dict holds strings emitted in the frame header. */

/* Tiny dictionaries skip content hashing; pointer hits still deduplicate.
 * Missed content matches cost duplicate inline bytes, not correctness. */
#define HASH_MAP_THRESHOLD 4

/* Pack the kind into idx to keep intern_slot at 16 bytes: high bit means
 * inline-emitted; a repeat upgrades to a dictionary index in the low 31 bits. */
#define SLOT_KIND_BIT       0x80000000u
#define SLOT_IS_DICT(s)     (((s).idx & SLOT_KIND_BIT) == 0)
#define SLOT_DICT_IDX(s)    ((s).idx)
#define SLOT_INLINE_MARK    SLOT_KIND_BIT  /* sentinel idx for inline-only slots */

typedef struct {
    zend_string *ptr;
    uint32_t idx;  /* high bit set = INLINE_EMITTED, clear = DICT_IDX with idx in low 31 bits */
} intern_slot;

/* AFFINE materializes O(n) zvals from O(1) bytes. Bound cumulative expansion to
 * about 16 MB; the encoder falls back to linear tags beyond the same budget. */
#define PHPSER_SUBLINEAR_MAX_ELEMS (1u << 20)

/* Tracked entities are pinned before user code runs, preventing address reuse from
 * masquerading as a back-reference. Unique plain objects still claim ids without
 * table entries or delayed destruction. */
enum { ENC_ID_OBJECT, ENC_ID_REFERENCE };

typedef struct {
    uintptr_t ptr;   /* 0 = empty */
    uint32_t  id;
    uint8_t   kind;
} id_entry;

typedef struct {
    /* Pointer-keyed cache; NULL marks an empty slot. No eviction. */
    intern_slot *icache;
    uint32_t icache_mask;    /* capacity-1 (power of 2); 0 = unallocated */
    uint32_t icache_count;
    HashTable hash_map;
    uint8_t   hash_map_inited;   /* lazy init: skipped for small payloads */
    /* Per-payload id table for tracked (zend_object*|zend_reference*) entities.
     * Every id-claiming value increments next_id; repeatable entities are
     * recorded here and subsequent visits emit TAG_REF + id. */
    id_entry *id_buckets;
    uint32_t id_mask;       /* capacity - 1, capacity is power of 2; 0 means unallocated */
    uint32_t id_count;
    uint32_t next_id;
    /* Once user code can run, every id-table entry owns a reference.
     * Activation pins existing entries; later inserts pin individually. */
    uint8_t pins_active;
    /* First-allocation capacity for icache (power of 2; 0 = default 32).
     * Seeded from the top-level element count so a large payload skips the
     * per-doubling ecalloc + rehash cascade on its way up from 32 slots. */
    uint32_t icache_init_cap;
    /* Elements already emitted under sub-linear tags; see
     * PHPSER_SUBLINEAR_MAX_ELEMS. */
    uint32_t sublinear_elems;
    zend_string **dict;
    uint32_t dict_len;
    uint32_t dict_cap;
    uint32_t depth;
    /* A depth placeholder keeps the body well-formed but makes the frame
     * lossy; the caller must discard it. */
    uint8_t depth_exceeded;
    /* Reject strings beyond the decoder's UINT32_MAX limit during encode. */
    uint8_t size_exceeded;
    /* A hook exception invalidates the entire frame, including session saves. */
    uint8_t failed;
    /* Set when the body uses a v2-only tag; header version becomes 0x02. */
    uint8_t wire_v2;
} encode_ctx;

static void enc_ctx_init(encode_ctx *e) {
    e->icache = NULL;
    e->icache_mask = 0;
    e->icache_count = 0;
    e->hash_map_inited = 0;     /* zend_hash_init deferred until dict crosses HASH_MAP_THRESHOLD */
    e->id_buckets = NULL;
    e->id_mask = 0;
    e->id_count = 0;
    e->next_id = 0;
    e->dict = NULL;
    e->dict_len = 0;
    e->dict_cap = 0;
    e->depth = 0;
    e->depth_exceeded = 0;
    e->size_exceeded = 0;
    e->failed = 0;
    e->wire_v2 = 0;
    e->pins_active = 0;
    e->icache_init_cap = 0;
    e->sublinear_elems = 0;
}

/* Live declared-property slots in properties_info_table order (NULL entries
 * skipped). TAG_OBJECT_SLOTS uses this as its positional per-class schema. */
static uint32_t ce_table_slot_count(zend_class_entry *ce) {
    uint32_t n = 0;
    int pc = ce->default_properties_count;
    for (int pi = 0; pi < pc; pi++) {
        if (ce->properties_info_table[pi] != NULL) n++;
    }
    return n;
}

/* Exclusive properties_info_table index covering the first `nprops` live
 * slots. The current-schema path returns the full table immediately; only an
 * older append-only payload needs the prefix scan. */
static int ce_table_slot_prefix_limit(
    zend_class_entry *ce, uint32_t nprops, uint32_t current_nprops)
{
    int pc = ce->default_properties_count;
    if (nprops == current_nprops) return pc;
    if (nprops == 0) return 0;

    uint32_t seen = 0;
    for (int pi = 0; pi < pc; pi++) {
        if (ce->properties_info_table[pi] != NULL && ++seen == nprops) {
            return pi + 1;
        }
    }
    return pc;
}

static zend_always_inline void enc_id_release(id_entry *entry) {
    if (entry->kind == ENC_ID_OBJECT) {
        OBJ_RELEASE((zend_object *)entry->ptr);
    } else {
        GC_DTOR((zend_reference *)entry->ptr);
    }
}

static zend_always_inline void enc_id_addref(uintptr_t ptr, uint8_t kind) {
    if (kind == ENC_ID_OBJECT) {
        GC_ADDREF((zend_object *)ptr);
    } else {
        GC_ADDREF((zend_reference *)ptr);
    }
}

/* First user-code boundary: pin every tracked entity before the hook runs,
 * so a hook (or a destructor it triggers) cannot destroy a table entry and
 * let a same-address reallocation masquerade as a back-reference. Called
 * immediately before __serialize / __sleep / ce->serialize / lazy-object
 * initialization; hook-free payloads never pay the refcount traffic. */
static zend_never_inline void enc_pins_activate_slow(encode_ctx *e) {
    e->pins_active = 1;
    if (e->id_buckets) {
        for (uint32_t i = 0; i <= e->id_mask; i++) {
            if (e->id_buckets[i].ptr) {
                enc_id_addref(e->id_buckets[i].ptr, e->id_buckets[i].kind);
            }
        }
    }
}

static zend_always_inline void enc_pins_activate(encode_ctx *e) {
    if (UNEXPECTED(!e->pins_active)) enc_pins_activate_slow(e);
}

static void enc_ctx_destroy(encode_ctx *e) {
    if (e->icache) efree(e->icache);
    if (e->hash_map_inited) zend_hash_destroy(&e->hash_map);
    if (e->id_buckets) {
        if (e->pins_active) {
            for (uint32_t i = 0; i <= e->id_mask; i++) {
                if (e->id_buckets[i].ptr) enc_id_release(&e->id_buckets[i]);
            }
        }
        efree(e->id_buckets);
    }
    if (e->dict) {
        for (uint32_t i = 0; i < e->dict_len; i++) {
            zend_string_release(e->dict[i]);
        }
        efree(e->dict);
    }
}

/* Mix the pointer down: object/reference allocations are 8/16-byte aligned,
 * so the low bits are useless. A Fibonacci-style multiply spreads them. */
static inline uint32_t id_hash(uintptr_t p) {
    return (uint32_t)((p * 11400714819323198485ULL) >> 32);
}

static void enc_id_grow(encode_ctx *e) {
    uint32_t new_cap = e->id_mask ? (e->id_mask + 1) * 2 : 16;
    id_entry *new_buckets = ecalloc(new_cap, sizeof(id_entry));
    uint32_t new_mask = new_cap - 1;
    if (e->id_buckets) {
        for (uint32_t i = 0; i <= e->id_mask; i++) {
            uintptr_t p = e->id_buckets[i].ptr;
            if (!p) continue;
            uint32_t h = id_hash(p) & new_mask;
            while (new_buckets[h].ptr) h = (h + 1) & new_mask;
            new_buckets[h] = e->id_buckets[i];
        }
        efree(e->id_buckets);
    }
    e->id_buckets = new_buckets;
    e->id_mask = new_mask;
}

/* Returns 1 for a new entity, 0 for a repeat; *out_id holds its encounter id. */
static inline int enc_visit(encode_ctx *e, void *ptr, uint8_t kind,
                            uint32_t *out_id) {
    /* Grow when load factor would exceed 50% (cap is power of 2; count+1
     * after this call must not exceed cap/2). Keeps probe chains short. */
    if (UNEXPECTED((e->id_count + 1) * 2 > e->id_mask + 1)) {
        enc_id_grow(e);
    }
    uintptr_t pp = (uintptr_t)ptr;
    uint32_t h = id_hash(pp) & e->id_mask;
    id_entry *buckets = e->id_buckets;
    while (buckets[h].ptr) {
        if (buckets[h].ptr == pp) {
            *out_id = buckets[h].id;
            return 0;
        }
        h = (h + 1) & e->id_mask;
    }
    uint32_t id = e->next_id++;
    buckets[h].ptr = pp;
    buckets[h].id = id;
    buckets[h].kind = kind;
    if (UNEXPECTED(e->pins_active)) enc_id_addref(pp, kind);
    e->id_count++;
    *out_id = id;
    return 1;
}

/* TAG_NULL claims no decoder id, so undo speculative claims on hook failure.
 * No enc_visit may intervene: clearing the newest slot cannot break a probe
 * chain. Nested phpser_serialize calls have separate encode contexts. */
static inline void enc_unvisit_last(encode_ctx *e, void *ptr, int tracked) {
    if (!tracked) {
        e->next_id--;
        return;
    }
    uintptr_t pp = (uintptr_t)ptr;
    uint32_t h = id_hash(pp) & e->id_mask;
    while (e->id_buckets[h].ptr != pp) {
        h = (h + 1) & e->id_mask;
    }
    if (e->pins_active) enc_id_release(&e->id_buckets[h]);
    e->id_buckets[h].ptr = 0;
    e->id_count--;
    e->next_id--;
}

static inline intern_slot *enc_cache_find(encode_ctx *e, zend_string *zs) {
    if (!e->icache) return NULL;
    uint32_t h = id_hash((uintptr_t)zs) & e->icache_mask;
    intern_slot *c = e->icache;
    while (c[h].ptr) {
        if (c[h].ptr == zs) return &c[h];
        h = (h + 1) & e->icache_mask;
    }
    return NULL;
}

static void enc_icache_grow(encode_ctx *e) {
    uint32_t new_cap = e->icache_mask ? (e->icache_mask + 1) * 2
        : (e->icache_init_cap ? e->icache_init_cap : 32);
    intern_slot *nb = ecalloc(new_cap, sizeof(intern_slot));  /* ptr==NULL = empty */
    uint32_t nm = new_cap - 1;
    if (e->icache) {
        for (uint32_t i = 0; i <= e->icache_mask; i++) {
            zend_string *p = e->icache[i].ptr;
            if (!p) continue;
            uint32_t h = id_hash((uintptr_t)p) & nm;
            while (nb[h].ptr) h = (h + 1) & nm;
            nb[h] = e->icache[i];
        }
        efree(e->icache);
    }
    e->icache = nb;
    e->icache_mask = nm;
}

/* Insert `zs` (which the caller has confirmed absent via enc_cache_find) and
 * return its slot. No eviction: the cache grows with the payload's distinct
 * string count, so every pointer-shared repeat stays cached. The caller writes
 * .idx immediately; no enc_cache_alloc_slot call may intervene before that
 * write, so the returned pointer can't be invalidated by a rehash. */
static inline intern_slot *enc_cache_alloc_slot(encode_ctx *e, zend_string *zs) {
    if (UNEXPECTED((e->icache_count + 1) * 2 > e->icache_mask + 1)) {
        enc_icache_grow(e);
    }
    uint32_t h = id_hash((uintptr_t)zs) & e->icache_mask;
    intern_slot *c = e->icache;
    while (c[h].ptr) h = (h + 1) & e->icache_mask;
    c[h].ptr = zs;
    e->icache_count++;
    return &c[h];
}

/* Allocate a dict slot for `zs` and return its index. Maintains the content
 * hash_map once the dict crosses HASH_MAP_THRESHOLD entries; below that,
 * pointer equality already catches the literal-interned case. */
static uint32_t enc_dict_append(encode_ctx *e, zend_string *zs) {
    if (UNEXPECTED(ZSTR_LEN(zs) > UINT32_MAX)) e->size_exceeded = 1;
    if (e->dict_len == e->dict_cap) {
        e->dict_cap = e->dict_cap ? e->dict_cap * 2 : 16;
        e->dict = erealloc(e->dict, e->dict_cap * sizeof(zend_string *));
    }
    uint32_t idx = e->dict_len++;
    /* Own strings borrowed from hook temporaries until the dictionary header is
     * emitted. */
    e->dict[idx] = zend_string_copy(zs);

    if (e->dict_len == HASH_MAP_THRESHOLD) {
        /* Lazy init: pre-size the map to the threshold count to avoid an
         * internal rehash on the batch insert below. */
        zend_hash_init(&e->hash_map, HASH_MAP_THRESHOLD * 2, NULL, NULL, 0);
        e->hash_map_inited = 1;
        for (uint32_t i = 0; i < e->dict_len; i++) {
            zval iz; ZVAL_LONG(&iz, i);
            zend_hash_add(&e->hash_map, e->dict[i], &iz);
        }
    } else if (e->dict_len > HASH_MAP_THRESHOLD) {
        zval iz; ZVAL_LONG(&iz, idx);
        zend_hash_add(&e->hash_map, zs, &iz);
    }
    return idx;
}

/* A content hit pins the dictionary's string, not zs. Cache only interned zs:
 * a freed temporary's reused address could otherwise select the wrong index.
 * enc_dict_append differs: it owns a reference to zs itself. */
static zend_always_inline void enc_seed_dict_on_hit(
    encode_ctx *e, intern_slot *s, zend_string *zs, uint32_t idx)
{
    if (!ZSTR_IS_INTERNED(zs)) return;
    if (s) { s->idx = idx; } else { enc_cache_alloc_slot(e, zs)->idx = idx; }
}

/* Return 1 when `zs` is already in the dict (cache or content map); writes the
 * index to *out_idx. Used by TAG_ASSOC_DICT / PACKED_STRINGS eligibility. */
static zend_always_inline int enc_str_dict_idx(
    encode_ctx *e, zend_string *zs, uint32_t *out_idx)
{
    intern_slot *s = enc_cache_find(e, zs);
    if (s && SLOT_IS_DICT(*s)) {
        *out_idx = SLOT_DICT_IDX(*s);
        return 1;
    }
    if (e->dict_len >= HASH_MAP_THRESHOLD) {
        zval *hit = zend_hash_find(&e->hash_map, zs);
        if (hit) {
            uint32_t idx = (uint32_t)Z_LVAL_P(hit);
            enc_seed_dict_on_hit(e, s, zs, idx);
            *out_idx = idx;
            return 1;
        }
    }
    return 0;
}

static uint32_t enc_intern_zstr(encode_ctx *e, zend_string *zs) {
    intern_slot *s = enc_cache_find(e, zs);
    if (s && SLOT_IS_DICT(*s)) return SLOT_DICT_IDX(*s);

    /* Cache miss or INLINE_EMITTED. Try content lookup once the dict is big
     * enough to matter. */
    if (e->dict_len >= HASH_MAP_THRESHOLD) {
        zval *hit = zend_hash_find(&e->hash_map, zs);
        if (hit) {
            uint32_t idx = (uint32_t)Z_LVAL_P(hit);
            enc_seed_dict_on_hit(e, s, zs, idx);
            return idx;
        }
    }

    uint32_t idx = enc_dict_append(e, zs);
    if (s) { s->idx = idx; } else { enc_cache_alloc_slot(e, zs)->idx = idx; }
    return idx;
}

/* Emit first encounters inline; repeats promote to the dictionary without
 * rewriting earlier bytes. Single-use strings add no dictionary overhead. */
static zend_always_inline int enc_emit_str_tagged(
    smart_str *body, encode_ctx *e, zend_string *zs,
    uint8_t dict_tag, uint8_t inline_tag)
{
    intern_slot *s = enc_cache_find(e, zs);
    if (s) {
        if (SLOT_IS_DICT(*s)) {
            emit_tag_and_varint(body, dict_tag, SLOT_DICT_IDX(*s));
            return 0;
        }
        /* INLINE_EMITTED: upgrade in place. */
        s->idx = enc_dict_append(e, zs);  /* writes into low 31 bits; high bit cleared */
        emit_tag_and_varint(body, dict_tag, s->idx);
        return 0;
    }
    /* Cache miss above HASH_MAP_THRESHOLD: content dedup might still hit. */
    if (e->dict_len >= HASH_MAP_THRESHOLD) {
        zval *hit = zend_hash_find(&e->hash_map, zs);
        if (hit) {
            uint32_t idx = (uint32_t)Z_LVAL_P(hit);
            enc_seed_dict_on_hit(e, s, zs, idx);  /* s==NULL here; interned-only */
            emit_tag_and_varint(body, dict_tag, idx);
            return 0;
        }
    }
    /* First encounter: emit inline, mark in cache as INLINE_EMITTED so the
     * next occurrence triggers the upgrade above. Returns 0, or -1 when the
     * string exceeds the decoder's per-string cap (size_exceeded is set;
     * the caller decides the placeholder). */
    if (UNEXPECTED(ZSTR_LEN(zs) > UINT32_MAX)) {
        /* Over the decoder's per-string cap: flag and skip the copy. Appending
         * the >4 GiB body first (then freeing it in phpser_encode_zval) would
         * spike peak RSS by the string's full size for a frame we discard. */
        e->size_exceeded = 1;
        return -1;
    }
    smart_str_appendc(body, inline_tag);
    varint_write_u64(body, ZSTR_LEN(zs));
    smart_str_appendl(body, ZSTR_VAL(zs), ZSTR_LEN(zs));
    enc_cache_alloc_slot(e, zs)->idx = SLOT_INLINE_MARK;
    return 0;
}

static void enc_emit_str_value(smart_str *body, encode_ctx *e, zend_string *zs) {
    if (enc_emit_str_tagged(body, e, zs, TAG_STR_DICT, TAG_STR_INLINE) < 0) {
        /* The frame is discarded via size_exceeded, but keep the body
         * structurally valid until then with a NULL placeholder so no
         * downstream pass ever walks a short buffer. */
        smart_str_appendc(body, TAG_NULL);
    }
}

/* Back-patch the count to avoid a second property walk. Counts >=128 need extra
 * varint bytes inserted into the body. */
static void enc_patch_nprops(smart_str *body, size_t off, uint32_t nprops) {
    if (EXPECTED(nprops < 0x80)) {
        ZSTR_VAL(body->s)[off] = (char)nprops;
        return;
    }
    char var[VARINT_MAX_BYTES];
    size_t len = varint_put(var, 0, nprops);
    size_t extra = len - 1;
    smart_str_alloc(body, extra, 0);
    char *base = ZSTR_VAL(body->s);
    size_t end = ZSTR_LEN(body->s);
    memmove(base + off + len, base + off + 1, end - (off + 1));
    memcpy(base + off, var, (size_t)len);
    ZSTR_LEN(body->s) = end + extra;
}

static void enc_emit_str_key(smart_str *body, encode_ctx *e, zend_string *zs) {
    /* Keys have no placeholder (a TAG_NULL is invalid in a key slot), so an
     * over-4GiB key relies on size_exceeded dropping the whole frame. */
    (void)enc_emit_str_tagged(body, e, zs, KEY_STR, KEY_STR_INLINE);
}

/* -------------------------------------------------------------------------
 * Encode dispatch.
 * ------------------------------------------------------------------------- */

static void encode_value(smart_str *body, encode_ctx *e, zval *v);
static void encode_value_ex(smart_str *body, encode_ctx *e, zval *v,
                            bool in_rcn_array);
static void encode_value_inner(smart_str *body, encode_ctx *e, zval *v,
                               bool in_rcn_array);
static void encode_hashtable(smart_str *body, encode_ctx *e, HashTable *ht,
                             bool in_rcn_array, bool ht_shared);

/* Declared properties use IS_INDIRECT; uninitialized slots use IS_UNDEF. Skip
 * keyless and undefined buckets so emitted counts match values. */
static zend_always_inline zval *enc_obj_prop_val(Bucket *b) {
    if (!b->key) return NULL;
    zval *v = &b->val;
    if (Z_TYPE_P(v) == IS_INDIRECT) v = Z_INDIRECT_P(v);
    if (Z_TYPE_P(v) == IS_UNDEF) return NULL;
    return v;
}

static void encode_value(smart_str *body, encode_ctx *e, zval *v) {
    encode_value_ex(body, e, v, false);
}

static void encode_value_ex(smart_str *body, encode_ctx *e, zval *v,
                            bool in_rcn_array) {
    /* After a hook throws, placeholders preserve container counts until the
     * caller discards the frame. */
    if (UNEXPECTED(e->failed)) {
        smart_str_appendc(body, TAG_NULL);
        return;
    }
    /* Declared properties surface as IS_INDIRECT in get_properties() HTs,
     * pointing at the real slot in properties_table[]. Without the deref
     * every typed property would emit NULL. */
    if (Z_TYPE_P(v) == IS_INDIRECT) {
        v = Z_INDIRECT_P(v);
    }
    /* Keep the partial body well-formed; the caller rejects the entire frame. */
    if (UNEXPECTED(e->depth >= MAX_DEPTH)) {
        e->depth_exceeded = 1;
        smart_str_appendc(body, TAG_NULL);
        return;
    }
    e->depth++;
    encode_value_inner(body, e, v, in_rcn_array);
    e->depth--;
}

/* Keep the __sleep snapshot's key/value ownership and cleanup rules together. */
static zend_always_inline void enc_encode_sleep_object(
    smart_str *body, encode_ctx *e, zend_object *obj,
    zend_function *sleep_fn, uint32_t class_idx, bool identity_tracked)
{
    zval names_zv;
    ZVAL_UNDEF(&names_zv);
    enc_pins_activate(e);
    zend_call_known_instance_method_with_0_params(sleep_fn, obj, &names_zv);
    if (UNEXPECTED(EG(exception)) || Z_TYPE(names_zv) != IS_ARRAY) {
        /* Same id-rollback as the __serialize failure path. A non-array
         * __sleep return (no exception) matches native: emit the warning and
         * serialize null in the object's place. Only a thrown exception aborts
         * the whole frame. */
        if (!EG(exception) && Z_TYPE(names_zv) != IS_ARRAY) {
            php_error_docref(NULL, E_WARNING,
                "%s::__sleep() should return an array only containing the names of instance-variables to serialize",
                ZSTR_VAL(obj->ce->name));
        }
        zval_ptr_dtor(&names_zv);
        enc_unvisit_last(e, obj, identity_tracked);
        if (EG(exception)) e->failed = 1;
        smart_str_appendc(body, TAG_NULL);
        return;
    }

    HashTable *names_ht = Z_ARRVAL(names_zv);
    /* Fix the selected member set at __sleep-return time. Own both keys and
     * values because emitting an earlier value can run a nested hook that
     * mutates the object or replaces a referenced name in `names_ht`. */
    zend_string **snap_keys = NULL;
    zval *snap_vals = NULL;
    uint32_t nprops = 0, snap_cap = 0;
    zval *zv_name;
    ZEND_HASH_FOREACH_VAL(names_ht, zv_name) {
        ZVAL_DEREF(zv_name);
        zend_string *tmp_name = NULL;
        zend_string *nm;
        if (Z_TYPE_P(zv_name) != IS_STRING) {
            php_error_docref(NULL, E_WARNING,
                "%s::__sleep() should return an array only containing the names of instance-variables to serialize",
                ZSTR_VAL(obj->ce->name));
            nm = zval_get_tmp_string(zv_name, &tmp_name);
            if (UNEXPECTED(EG(exception))) {
                zend_tmp_string_release(tmp_name);
                e->failed = 1;
                break;
            }
        } else {
            nm = Z_STR_P(zv_name);
        }

        zend_property_info *info = zend_hash_find_ptr(
            &obj->ce->properties_info, nm);
        zend_string *key;
        zval *p;
        if (info != NULL) {
            if (info->flags & ZEND_ACC_STATIC) {
                zend_tmp_string_release(tmp_name);
                continue;
            }
#if PHP_VERSION_ID >= 80400
            if (info->offset == (uint32_t)ZEND_VIRTUAL_PROPERTY_OFFSET) {
                zend_tmp_string_release(tmp_name);
                continue;
            }
#endif
            p = OBJ_PROP(obj, info->offset);
            if (Z_TYPE_P(p) == IS_UNDEF) {
                zend_tmp_string_release(tmp_name);
                continue;
            }
            key = info->name;
        } else if (obj->properties
                   && (p = zend_hash_find(obj->properties, nm)) != NULL
                   && Z_TYPE_P(p) != IS_UNDEF) {
            key = nm;
        } else {
            zend_tmp_string_release(tmp_name);
            continue;
        }

        if (nprops == snap_cap) {
            snap_cap = snap_cap ? snap_cap * 2 : 8;
            snap_keys = erealloc(
                snap_keys, snap_cap * sizeof(zend_string *));
            snap_vals = erealloc(snap_vals, snap_cap * sizeof(zval));
        }
        snap_keys[nprops] = zend_string_copy(key);
        ZVAL_COPY(&snap_vals[nprops], p);
        nprops++;
        zend_tmp_string_release(tmp_name);
    } ZEND_HASH_FOREACH_END();

    smart_str_appendc(body, TAG_OBJECT);
    varint_write_u64(body, class_idx);
    varint_write_u64(body, nprops);
    for (uint32_t i = 0; i < nprops; i++) {
        varint_write_u64(body, enc_intern_zstr(e, snap_keys[i]));
        encode_value_ex(body, e, &snap_vals[i], true);
    }
    for (uint32_t i = 0; i < nprops; i++) {
        zend_string_release(snap_keys[i]);
        zval_ptr_dtor(&snap_vals[i]);
    }
    if (snap_keys) efree(snap_keys);
    if (snap_vals) efree(snap_vals);
    zval_ptr_dtor(&names_zv);
}

static void encode_value_inner(smart_str *body, encode_ctx *e, zval *v,
                               bool in_rcn_array) {
    switch (Z_TYPE_P(v)) {
        case IS_UNDEF:
        case IS_NULL:
            smart_str_appendc(body, TAG_NULL);
            return;
        case IS_FALSE:
            smart_str_appendc(body, TAG_FALSE);
            return;
        case IS_TRUE:
            smart_str_appendc(body, TAG_TRUE);
            return;
        case IS_LONG:
            emit_tag_and_varint(body, TAG_LONG, zigzag_encode64(Z_LVAL_P(v)));
            return;
        case IS_DOUBLE: {
            smart_str_appendc(body, TAG_DOUBLE);
            smart_str_append_le64(body, Z_DVAL_P(v));
            return;
        }
        case IS_STRING:
            enc_emit_str_value(body, e, Z_STR_P(v));
            return;
        case IS_ARRAY: {
            HashTable *ht = Z_ARRVAL_P(v);
            /* Shared-ness propagates transitively: an RC1 array reached from a
             * shared (RC>1) ancestor is still walked once per ancestor visit,
             * so its object children need identity tracking too. */
            /* Measure sharing before our protective addref obscures external
             * ownership. */
            bool ht_shared =
                !(GC_FLAGS(ht) & GC_IMMUTABLE) && GC_REFCOUNT(ht) > 1;
            bool children_in_rcn_array = in_rcn_array || ht_shared;
            /* Hooks may mutate this array through aliases. An extra reference forces
             * zval writes to COW-separate, preserving cached bucket pointers.
             * SPL's internal storage writes bypass COW; enc_pin_walk and the
             * columnar gather separately protect those paths. */
            GC_TRY_ADDREF(ht);
            encode_hashtable(body, e, ht, children_in_rcn_array, ht_shared);
            if (!(GC_FLAGS(ht) & GC_IMMUTABLE) && !GC_DELREF(ht)) {
                zend_array_destroy(ht);
            }
            return;
        }
        case IS_REFERENCE: {
            /* A repeated zend_reference emits a back-ref; a new one claims an
             * id and emits TAG_NEW_REF + inner value. */
            zend_reference *ref = Z_REF_P(v);
            uint32_t id;
            if (!enc_visit(e, ref, ENC_ID_REFERENCE, &id)) {
                emit_tag_and_varint(body, TAG_REF, id);
                return;
            }
            smart_str_appendc(body, TAG_NEW_REF);
            /* The decoder registers the reference at next_id++ before
             * decoding the inner value, so a back-ref inside it resolves to
             * this reference. */
            encode_value_ex(body, e, Z_REFVAL_P(v), true);
            return;
        }
        case IS_OBJECT: {
            zend_object *obj = Z_OBJ_P(v);
            /* NOT_SERIALIZABLE classes (Closure, Generator, etc.) can't be
             * rebuilt by object_init_ex. Emit NULL; native serialize() throws
             * here instead. */
            if (obj->ce->ce_flags & ZEND_ACC_NOT_SERIALIZABLE) {
                smart_str_appendc(body, TAG_NULL);
                return;
            }
            /* Object identity (PHP's `r:N`): a repeat emits a back-ref; a new
             * object claims an id taken implicitly by the container tag below. */
            /* Cheap disjuncts first: a repeat visit of an already-tracked
             * object returns below without paying the __sleep function_table
             * lookup, which otherwise runs on every back-reference. */
            bool identity_tracked = in_rcn_array
                || GC_REFCOUNT(obj) > 1
                || (obj->properties && GC_REFCOUNT(obj->properties) > 1)
                || obj->ce->__serialize != NULL;
            zval *sleep_fn_zv = NULL;
            if (!identity_tracked) {
                sleep_fn_zv = zend_hash_find_known_hash(
                    &obj->ce->function_table, ZSTR_KNOWN(ZEND_STR_SLEEP));
                identity_tracked = sleep_fn_zv != NULL;
            }
            if (identity_tracked) {
                uint32_t id;
                if (!enc_visit(e, obj, ENC_ID_OBJECT, &id)) {
                    emit_tag_and_varint(body, TAG_REF, id);
                    return;
                }
                if (sleep_fn_zv == NULL) {
                    sleep_fn_zv = zend_hash_find_known_hash(
                        &obj->ce->function_table, ZSTR_KNOWN(ZEND_STR_SLEEP));
                }
            } else {
                e->next_id++;
            }
            /* Legacy C-level serializer (Serializable, SplPriorityQueue,
             * SplMinHeap, ...). As in PHP, __serialize takes precedence. */
            if (obj->ce->__serialize == NULL && obj->ce->serialize != NULL) {
                unsigned char *data = NULL;
                size_t len = 0;
                /* Legacy serializers have separate reference tracking; identity
                 * sharing does not cross that boundary. */
                enc_pins_activate(e);
                if (obj->ce->serialize(v, &data, &len, NULL) != SUCCESS) {
                    if (data) efree(data);
                    /* Native serializes null on hook FAILURE without an exception.
                     * Roll back the id because TAG_NULL claims none. */
                    enc_unvisit_last(e, obj, identity_tracked);
                    if (EG(exception)) e->failed = 1;
                    smart_str_appendc(body, TAG_NULL);
                    return;
                }
                if (UNEXPECTED(EG(exception))) {
                    /* SUCCESS with a pending exception: native rechecks after
                     * the hook regardless of its return, so abort as on the
                     * __serialize path. */
                    if (data) efree(data);
                    enc_unvisit_last(e, obj, identity_tracked);
                    e->failed = 1;
                    smart_str_appendc(body, TAG_NULL);
                    return;
                }
                if (UNEXPECTED(len > UINT32_MAX)) {
                    /* Reject before copying a >4 GiB blob to avoid doubling peak
                     * memory. Roll back the id and preserve the value count with a
                     * placeholder. */
                    e->size_exceeded = 1;
                    if (data) efree(data);
                    enc_unvisit_last(e, obj, identity_tracked);
                    smart_str_appendc(body, TAG_NULL);
                    return;
                }
                uint32_t class_idx = enc_intern_zstr(e, obj->ce->name);
                emit_tag_and_varint(body, TAG_OBJECT_LEGACY, class_idx);
                varint_write_u64(body, len);
                if (len > 0 && data) smart_str_appendl(body, (const char *)data, len);
                if (data) efree(data);
                return;
            }
            /* __serialize() takes precedence over property iteration
             * (ArrayObject, SplObjectStorage, DateTime, ...). */
            if (obj->ce->__serialize != NULL) {
                zval retval;
                ZVAL_UNDEF(&retval);
                enc_pins_activate(e);
                zend_call_known_instance_method_with_0_params(
                    obj->ce->__serialize, obj, &retval);
                if (UNEXPECTED(EG(exception)) || Z_TYPE(retval) != IS_ARRAY) {
                    /* A non-array __serialize result raises TypeError; preserve any
                     * exception the hook already raised. */
                    if (!EG(exception) && Z_TYPE(retval) != IS_ARRAY) {
                        zend_type_error(
                            "%s::__serialize() must return an array",
                            ZSTR_VAL(obj->ce->name));
                    }
                    zval_ptr_dtor(&retval);
                    enc_unvisit_last(e, obj, identity_tracked);
                    /* An exception is pending (hook threw or TypeError above);
                     * abort rather than ship a frame with a TAG_NULL hole. */
                    if (EG(exception)) e->failed = 1;
                    smart_str_appendc(body, TAG_NULL);
                    return;
                }
                uint32_t class_idx = enc_intern_zstr(e, obj->ce->name);
                emit_tag_and_varint(body, TAG_OBJECT_MAGIC, class_idx);
                encode_value(body, e, &retval);
                zval_ptr_dtor(&retval);
                return;
            }
            /* Enums are singletons object_init_ex can't recreate; emit class +
             * case name for zend_enum_get_case on decode. */
            if (obj->ce->ce_flags & ZEND_ACC_ENUM) {
                uint32_t class_idx = enc_intern_zstr(e, obj->ce->name);
                zval *cname = zend_enum_fetch_case_name(obj);
                uint32_t case_idx = enc_intern_zstr(e, Z_STR_P(cname));
                emit_tag_and_varint(body, TAG_ENUM, class_idx);
                varint_write_u64(body, case_idx);
                return;
            }
            /* Recover the original class name so filtered objects can later decode
             * as that class. Omit the reserved marker from their properties. */
            zend_string *ic_name = NULL;
            if (UNEXPECTED(obj->ce == PHP_IC_ENTRY)) {
                ic_name = php_lookup_class_name(obj);  /* +1 ref, or NULL */
            }
            uint32_t class_idx = enc_intern_zstr(e, ic_name ? ic_name : obj->ce->name);
            if (ic_name) zend_string_release(ic_name);
            /* __sleep is looked up in function_table, not a zend_class_entry field.
             * Its selected properties use ordinary TAG_OBJECT wire format. */
            if (sleep_fn_zv != NULL) {
                enc_encode_sleep_object(
                    body, e, obj, Z_FUNC_P(sleep_fn_zv), class_idx,
                    identity_tracked);
                return;
            }
            /* Direct slots avoid materializing a properties HashTable. Custom
             * handlers, dynamic properties, and lazy objects need the normal path;
             * lazy slots must initialize before reading. */
            zend_class_entry *ce = obj->ce;
            if (obj->properties == NULL
                && obj->handlers->get_properties == zend_std_get_properties
#if PHP_VERSION_ID >= 80400
                && !zend_object_is_lazy(obj)
#endif
                && ce->__unserialize == NULL
                ) {
                int pc = ce->default_properties_count;
                bool slots_ok = true;
                uint32_t slot_count = 0;
                for (int pi = 0; pi < pc; pi++) {
                    zend_property_info *info = ce->properties_info_table[pi];
                    if (info == NULL) continue;
                    if (Z_TYPE_P(OBJ_PROP(obj, info->offset)) == IS_UNDEF) {
                        slots_ok = false;
                        break;
                    }
                    slot_count++;
                }
                if (slots_ok) {
                    uint32_t fp_nprops = slot_count;
                    /* Wire v2: values only, in declaration-table order. Any
                     * IS_UNDEF slot falls back to keyed TAG_OBJECT. */
                    e->wire_v2 = 1;
                    smart_str_appendc(body, TAG_OBJECT_SLOTS);
                    varint_write_u64(body, class_idx);
                    size_t nprops_off = ZSTR_LEN(body->s);
                    smart_str_appendc(body, 0);
                    /* A hook can mutate later slots. Snapshot from the first
                     * hook-capable value onward; preceding scalar/string slots
                     * cannot re-enter PHP. */
                    zval snap_stack[4];
                    zval *snap = NULL;
                    uint32_t snap_i = 0, emitted = 0;
                    for (int pi = 0; pi < pc; pi++) {
                        zend_property_info *info = ce->properties_info_table[pi];
                        if (info == NULL) continue;
                        zval *pv = OBJ_PROP(obj, info->offset);
                        if (snap == NULL && Z_TYPE_P(pv) >= IS_ARRAY) {
                            uint32_t snap_n = fp_nprops - emitted;
                            /* Typical DTOs have a short array/object tail after
                             * their scalar columns; keep those snapshots off
                             * the allocator. */
                            snap = snap_n <= 4 ? snap_stack
                                : (zval *)safe_emalloc(snap_n, sizeof(zval), 0);
                            uint32_t k = 0;
                            for (int pj = pi; pj < pc; pj++) {
                                zend_property_info *tail = ce->properties_info_table[pj];
                                if (tail == NULL) continue;
                                ZVAL_COPY(&snap[k++], OBJ_PROP(obj, tail->offset));
                            }
                            ZEND_ASSERT(k == snap_n);
                        }
                        encode_value_ex(body, e, snap ? &snap[snap_i++] : pv, true);
                        emitted++;
                    }
                    enc_patch_nprops(body, nprops_off, fp_nprops);
                    if (snap) {
                        for (uint32_t k = 0; k < snap_i; k++) {
                            zval_ptr_dtor(&snap[k]);
                        }
                        if (snap != snap_stack) efree(snap);
                    }
                    return;
                }
                /* Uninitialized typed slot: keyed TAG_OBJECT, skip IS_UNDEF. */
                smart_str_appendc(body, TAG_OBJECT);
                varint_write_u64(body, class_idx);
                size_t nprops_off = ZSTR_LEN(body->s);
                smart_str_appendc(body, 0);
                uint32_t fp_nprops = 0;
                for (int pi = 0; pi < pc; pi++) {
                    zend_property_info *info = ce->properties_info_table[pi];
                    if (info == NULL) continue;
                    zval *p = OBJ_PROP(obj, info->offset);
                    if (Z_TYPE_P(p) == IS_UNDEF) continue;
                    varint_write_u64(body, enc_intern_zstr(e, info->name));
                    encode_value_ex(body, e, p, true);
                    fp_nprops++;
                }
                enc_patch_nprops(body, nprops_off, fp_nprops);
                return;
            }
            /* zend_get_properties_for pins the table, forcing hook-triggered writes
             * to COW-separate, and initializes lazy objects. The bare handler
             * provides neither guarantee. */
            if (obj->handlers->get_properties != zend_std_get_properties
                || obj->handlers->get_properties_for != NULL
#if PHP_VERSION_ID >= 80400
                || zend_object_is_lazy(obj)
#endif
                ) {
                /* Custom get_properties_for takes precedence even if get_properties
                 * is standard; treat either custom handler and lazy initialization
                 * as user-code boundaries. */
                enc_pins_activate(e);
            }
            HashTable *props = zend_get_properties_for(v, ZEND_PROP_PURPOSE_SERIALIZE);
            /* Lazy initialization can throw; invalidate the frame and undo the
             * speculative id just as for serialization hooks. */
            if (UNEXPECTED(EG(exception))) {
                zend_release_properties(props);
                enc_unvisit_last(e, obj, identity_tracked);
                e->failed = 1;
                smart_str_appendc(body, TAG_NULL);
                return;
            }
            /* Single bucket walk; nprops back-patched after the loop. See
             * enc_obj_prop_val for the IS_INDIRECT / IS_UNDEF skip rules. */
            smart_str_appendc(body, TAG_OBJECT);
            varint_write_u64(body, class_idx);
            size_t nprops_off = ZSTR_LEN(body->s);
            smart_str_appendc(body, 0);  /* placeholder, patched below */
            uint32_t nprops = 0;
            /* For an incomplete class, class_idx above already carries the
             * recovered original name; drop the magic name-carrier member so
             * the emitted object matches one that was never filtered. */
            bool skip_magic = UNEXPECTED(obj->ce == PHP_IC_ENTRY);
            if (props) {
                Bucket *b = props->arData;
                Bucket *end = b + props->nNumUsed;
                for (; b < end; b++) {
                    zval *pv = enc_obj_prop_val(b);
                    if (!pv) continue;
                    if (skip_magic && b->key
                        && zend_string_equals_literal(b->key, MAGIC_MEMBER)) {
                        continue;
                    }
                    varint_write_u64(body, enc_intern_zstr(e, b->key));
                    encode_value_ex(body, e, pv, true);
                    nprops++;
                }
            }
            enc_patch_nprops(body, nprops_off, nprops);
            zend_release_properties(props);
            return;
        }
        default:
            smart_str_appendc(body, TAG_NULL);
            return;
    }
}

/* Return 1 when packed rows share the same string-key schema as row 0. */
static int enc_match_rowset_schema(
    encode_ctx *e, zval *zp, uint32_t n_used, uint32_t **out_key_idx, uint32_t *out_ncols)
{
    *out_key_idx = NULL;
    if (n_used < 2 || Z_TYPE(zp[0]) != IS_ARRAY) {
        return 0;
    }
    HashTable *ht0 = Z_ARRVAL(zp[0]);
    if (HT_IS_PACKED(ht0) || ht0->nNumOfElements == 0) {
        return 0;
    }
    uint32_t ncols = ht0->nNumOfElements;
    Bucket *b0 = ht0->arData;
    Bucket *end0 = b0 + ht0->nNumUsed;

    /* Defer interning until every row matches, so an abandoned rowset probe
     * leaves the dictionary unchanged. */
    zend_string **k0 = (zend_string **)safe_emalloc((size_t)ncols, sizeof(zend_string *), 0);
    uint32_t col = 0;
    for (Bucket *b = b0; b < end0; b++) {
        if (Z_TYPE(b->val) == IS_UNDEF) continue;
        if (!b->key) {
            goto fail;
        }
        k0[col++] = b->key;
    }
    if (col != ncols) {
        goto fail;
    }

    for (uint32_t r = 1; r < n_used; r++) {
        if (Z_TYPE(zp[r]) != IS_ARRAY) {
            goto fail;
        }
        HashTable *ht = Z_ARRVAL(zp[r]);
        if (HT_IS_PACKED(ht) || ht->nNumOfElements != ncols) {
            goto fail;
        }
        Bucket *b = ht->arData;
        Bucket *end = b + ht->nNumUsed;
        Bucket *ref = b0;
        uint32_t matched = 0;
        for (; b < end; b++) {
            if (Z_TYPE(b->val) == IS_UNDEF) continue;
            while (ref < end0 && Z_TYPE(ref->val) == IS_UNDEF) ref++;
            if (ref >= end0 || !b->key || !ref->key) {
                goto fail;
            }
            if (b->key != ref->key && !zend_string_equals(b->key, ref->key)) {
                goto fail;
            }
            ref++;
            matched++;
        }
        if (matched != ncols) {
            goto fail;
        }
    }

    /* Schema confirmed across every row; intern row-0's field names now. */
    uint32_t *key_idx = (uint32_t *)safe_emalloc((size_t)ncols, sizeof(uint32_t), 0);
    for (uint32_t c = 0; c < ncols; c++) {
        key_idx[c] = enc_intern_zstr(e, k0[c]);
    }
    efree(k0);
    *out_key_idx = key_idx;
    *out_ncols = ncols;
    return 1;
fail:
    efree(k0);
    return 0;
}

static void enc_write_key_idx_run(smart_str *body, uint32_t *key_idx, uint32_t ncols) {
    smart_str_alloc(body, (size_t)ncols * VARINT_MAX_BYTES, 0);
    char *base = ZSTR_VAL(body->s);
    size_t pos = ZSTR_LEN(body->s);
    for (uint32_t i = 0; i < ncols; i++) {
        pos = varint_put(base, pos, key_idx[i]);
    }
    ZSTR_LEN(body->s) = pos;
}

static void enc_prebind_equal_packed_string_arrays(
    encode_ctx *e, zval **cells, uint32_t nrows)
{
    /* cells[1] below requires at least two rows. */
    if (nrows < 2) return;
    HashTable *first = Z_ARRVAL_P(cells[0]);
    if (!HT_IS_PACKED(first)
        || first->nNumUsed != first->nNumOfElements
        || first->nNumOfElements == 0) {
        return;
    }

    uint32_t count = first->nNumOfElements;
    zval *values = first->arPacked;
    for (uint32_t i = 0; i < count; i++) {
        if (Z_TYPE(values[i]) != IS_STRING) return;
    }

    HashTable *second = Z_ARRVAL_P(cells[1]);
    if (!HT_IS_PACKED(second)
        || second->nNumUsed != count
        || second->nNumOfElements != count) {
        return;
    }
    zval *second_values = second->arPacked;
    int same_ptrs = 1;
    for (uint32_t i = 0; i < count; i++) {
        if (Z_TYPE(second_values[i]) != IS_STRING
            || !zend_string_equals(Z_STR(values[i]), Z_STR(second_values[i]))) {
            return;
        }
        if (Z_STR(values[i]) != Z_STR(second_values[i])) same_ptrs = 0;
    }
    if (same_ptrs) return;

    for (uint32_t r = 2; r < nrows; r++) {
        HashTable *row = Z_ARRVAL_P(cells[r]);
        if (!HT_IS_PACKED(row)
            || row->nNumUsed != count
            || row->nNumOfElements != count) {
            return;
        }
        zval *row_values = row->arPacked;
        for (uint32_t i = 0; i < count; i++) {
            if (Z_TYPE(row_values[i]) != IS_STRING
                || !zend_string_equals(Z_STR(values[i]), Z_STR(row_values[i]))) {
                return;
            }
        }
    }

    for (uint32_t i = 0; i < count; i++) {
        enc_intern_zstr(e, Z_STR(values[i]));
    }
}

#define TABLE_STRING_CARDINALITY_MAX 8

/* Choose the wire tag for a gathered column. For a string column that resolves
 * to TAG_PACKED_STRINGS, *out_same_value records whether every cell has equal
 * bytes, while *out_string_idx carries per-row indices for low-cardinality
 * content. Unique columns stop content comparison after nine values. */
static uint8_t enc_detect_column_tag(
    encode_ctx *e, zval **cells, uint32_t nrows, int *out_same_value,
    uint32_t **out_string_idx)
{
    *out_same_value = 0;
    *out_string_idx = NULL;
    uint8_t t0 = Z_TYPE_P(cells[0]);
    for (uint32_t r = 1; r < nrows; r++) {
        if (Z_TYPE_P(cells[r]) != t0) {
            return TAG_PACKED_MIXED;
        }
    }
    if (t0 == IS_LONG) {
        /* Delta only: an affine column would break the table's
         * one-wire-byte-per-cell nrows bound (see the TAG_PACKED_AFFINE
         * definition). Constant-delta columns still land near one byte per
         * cell under DELTA. */
        if (nrows < 4) return TAG_PACKED_LONGS;
        uint64_t prev = (uint64_t)Z_LVAL_P(cells[0]);
        uint32_t plain_bytes = varint_len_u64(zigzag_encode64((int64_t)prev));
        uint32_t delta_bytes = plain_bytes;
        for (uint32_t r = 1; r < nrows; r++) {
            uint64_t cur = (uint64_t)Z_LVAL_P(cells[r]);
            plain_bytes += varint_len_u64(zigzag_encode64((int64_t)cur));
            delta_bytes += varint_len_u64(zigzag_encode64((int64_t)(cur - prev)));
            prev = cur;
        }
        return delta_bytes < plain_bytes ? TAG_PACKED_DELTA : TAG_PACKED_LONGS;
    }
    if (t0 == IS_DOUBLE) {
        return TAG_PACKED_DOUBLES;
    }
    if (t0 == IS_STRING) {
        zend_string *unique[TABLE_STRING_CARDINALITY_MAX];
        uint32_t unique_idx[TABLE_STRING_CARDINALITY_MAX];
        uint32_t n_unique = 0;
        int all_dict = 1;
        int cardinality_overflow = 0;
        for (uint32_t r = 0; r < nrows; r++) {
            zend_string *sr = Z_STR_P(cells[r]);
            if (!cardinality_overflow) {
                uint32_t u = 0;
                for (; u < n_unique; u++) {
                    if (sr == unique[u] || zend_string_equals(sr, unique[u])) break;
                }
                if (u == n_unique) {
                    if (n_unique == TABLE_STRING_CARDINALITY_MAX) {
                        cardinality_overflow = 1;
                    } else {
                        unique[n_unique++] = sr;
                    }
                }
            }
            intern_slot *s = enc_cache_find(e, sr);
            if (!s || !SLOT_IS_DICT(*s)) {
                all_dict = 0;
            }
            if (cardinality_overflow && !all_dict) return TAG_PACKED_MIXED;
        }
        if (all_dict) {
            return TAG_PACKED_STRINGS;
        }
        if (!cardinality_overflow && n_unique < nrows) {
            for (uint32_t u = 0; u < n_unique; u++) {
                unique_idx[u] = enc_intern_zstr(e, unique[u]);
            }
            if (n_unique == 1) {
                *out_same_value = 1;
                return TAG_PACKED_STRINGS;
            }

            uint32_t *indices = (uint32_t *)safe_emalloc(
                (size_t)nrows, sizeof(uint32_t), 0);
            for (uint32_t r = 0; r < nrows; r++) {
                zend_string *sr = Z_STR_P(cells[r]);
                uint32_t u = 0;
                for (; u < n_unique; u++) {
                    if (sr == unique[u] || zend_string_equals(sr, unique[u])) {
                        break;
                    }
                }
                if (UNEXPECTED(u == n_unique)) {
                    efree(indices);
                    return TAG_PACKED_MIXED;
                }
                indices[r] = unique_idx[u];
            }
            *out_string_idx = indices;
            return TAG_PACKED_STRINGS;
        }
    } else if (t0 == IS_ARRAY) {
        /* Equal nested string vectors repeat by value in DB/cache rows even
         * when every driver result owns separate zend_strings. Prebinding one
         * vector lets the existing packed-string wire path reuse it. */
        enc_prebind_equal_packed_string_arrays(e, cells, nrows);
    }
    return TAG_PACKED_MIXED;
}

static void enc_emit_table_column(
    smart_str *body, encode_ctx *e, zval **cells, uint32_t nrows, uint8_t col_tag,
    int same_value, uint32_t *string_idx)
{
    smart_str_appendc(body, col_tag);
    if (col_tag == TAG_PACKED_LONGS) {
        smart_str_alloc(body, (size_t)nrows * VARINT_MAX_BYTES, 0);
        char *base = ZSTR_VAL(body->s);
        size_t pos = ZSTR_LEN(body->s);
        for (uint32_t r = 0; r < nrows; r++) {
            pos = varint_put(base, pos, zigzag_encode64(Z_LVAL_P(cells[r])));
        }
        ZSTR_LEN(body->s) = pos;
    } else if (col_tag == TAG_PACKED_DELTA) {
        smart_str_alloc(body, (size_t)nrows * VARINT_MAX_BYTES, 0);
        char *base = ZSTR_VAL(body->s);
        size_t pos = ZSTR_LEN(body->s);
        uint64_t prev = (uint64_t)Z_LVAL_P(cells[0]);
        pos = varint_put(base, pos, zigzag_encode64((int64_t)prev));
        for (uint32_t r = 1; r < nrows; r++) {
            uint64_t cur = (uint64_t)Z_LVAL_P(cells[r]);
            pos = varint_put(base, pos, zigzag_encode64((int64_t)(cur - prev)));
            prev = cur;
        }
        ZSTR_LEN(body->s) = pos;
    } else if (col_tag == TAG_PACKED_DOUBLES) {
        smart_str_alloc(body, (size_t)nrows * 8, 0);
        char *base = ZSTR_VAL(body->s);
        size_t pos = ZSTR_LEN(body->s);
        for (uint32_t r = 0; r < nrows; r++) {
            pos = le64_put(base, pos, Z_DVAL_P(cells[r]));
        }
        ZSTR_LEN(body->s) = pos;
    } else if (col_tag == TAG_PACKED_STRINGS) {
        smart_str_alloc(body, (size_t)nrows * VARINT_MAX_BYTES, 0);
        char *base = ZSTR_VAL(body->s);
        size_t pos = ZSTR_LEN(body->s);
        /* same_value comes from enc_detect_column_tag; no second walk. */
        zend_string *s0 = Z_STR_P(cells[0]);
        uint64_t same_idx = same_value ? enc_intern_zstr(e, s0) : 0;
        for (uint32_t r = 0; r < nrows; r++) {
            uint64_t v;
            if (same_value) {
                v = same_idx;
            } else if (string_idx) {
                v = string_idx[r];
            } else {
                intern_slot *sl = enc_cache_find(e, Z_STR_P(cells[r]));
                v = SLOT_DICT_IDX(*sl);
            }
            pos = varint_put(base, pos, v);
        }
        ZSTR_LEN(body->s) = pos;
    } else {
        for (uint32_t r = 0; r < nrows; r++) {
            encode_value_ex(body, e, cells[r], true);
        }
    }
}

/* Release the per-row pins taken by enc_try_table's gather. Each non-NULL slot
 * is either a private duplicate (zend_array_dup -> refcount 1) or the original
 * row we addref'd; a single GC_DELREF balances both, destroying the table only
 * when our reference was the last (a duplicate always, or an original whose
 * owning slot a hook deleted). NULL slots are immutable rows, never pinned. */
static void enc_free_row_pins(HashTable **row_pin, uint32_t n_used) {
    for (uint32_t r = 0; r < n_used; r++) {
        if (row_pin[r] && !GC_DELREF(row_pin[r])) {
            zend_array_destroy(row_pin[r]);
        }
    }
    efree(row_pin);
}

static zend_never_inline int enc_try_table(
    smart_str *body, encode_ctx *e, zval *zp, uint32_t n_used)
{
    uint32_t ncols, *key_idx;
    if (!enc_match_rowset_schema(e, zp, n_used, &key_idx, &ncols)) {
        return 0;
    }

    /* Gather row-major into column-major storage: the validated schema gives
     * each live bucket's column without rescanning rows. */
    zval **col_cells = (zval **)safe_emalloc((size_t)ncols * n_used, sizeof(zval *), 0);
    /* Mixed-column hooks can mutate ArrayObject/ArrayIterator storage without
     * COW. Duplicate shared rows to prevent bucket reallocation; pin nested
     * RC-1 rows against deletion from the outer storage. Top-level RC-1 rows
     * and immutable rows need no pin. Gather reads zp before any hook runs. */
    HashTable **row_pin = NULL;
    for (uint32_t r = 0; r < n_used; r++) {
        HashTable *ht = Z_ARRVAL(zp[r]);
        HashTable *pinned = NULL;
        if (!(GC_FLAGS(ht) & GC_IMMUTABLE)) {
            if (GC_REFCOUNT(ht) > 1) {
                pinned = zend_array_dup(ht);   /* private copy, refcount 1 */
                ht = pinned;
            } else if (e->depth > 1) {
                GC_ADDREF(ht);                 /* pin a nested RC-1 row against free */
                pinned = ht;
            }
        }
        if (pinned) {
            if (!row_pin) {
                row_pin = (HashTable **)ecalloc(n_used, sizeof(HashTable *));
            }
            row_pin[r] = pinned;
        }
        Bucket *b = ht->arData;
        Bucket *end = b + ht->nNumUsed;
        uint32_t c = 0;
        for (; b < end && c < ncols; b++) {
            if (Z_TYPE(b->val) == IS_UNDEF) continue;
            col_cells[c * n_used + r] = &b->val;
            c++;
        }
        if (UNEXPECTED(c != ncols)) {
            efree(col_cells);
            if (row_pin) enc_free_row_pins(row_pin, n_used);
            efree(key_idx);
            return 0;
        }
    }

    e->wire_v2 = 1;
    smart_str_appendc(body, TAG_TABLE);
    varint_write_u64(body, n_used);
    varint_write_u64(body, ncols);
    enc_write_key_idx_run(body, key_idx, ncols);
    efree(key_idx);

    for (uint32_t c = 0; c < ncols; c++) {
        int same_value;
        uint32_t *string_idx;
        uint8_t col_tag = enc_detect_column_tag(
            e, &col_cells[c * n_used], n_used, &same_value, &string_idx);
        enc_emit_table_column(
            body, e, &col_cells[c * n_used], n_used, col_tag, same_value,
            string_idx);
        if (string_idx) efree(string_idx);
    }
    efree(col_cells);
    if (row_pin) enc_free_row_pins(row_pin, n_used);
    return 1;
}

/* AFFINE requires constant wrapping deltas and available expansion budget.
 * Otherwise use DELTA only when smaller than plain longs. Arithmetic wraps mod
 * 2^64; runs shorter than four skip sizing. */
static uint8_t enc_pick_long_run_tag(encode_ctx *e, const zval *zp,
                                     uint32_t n, int64_t *out_step) {
    if (n < 4) return TAG_PACKED_LONGS;
    uint64_t prev = (uint64_t)Z_LVAL(zp[0]);
    uint64_t step0 = (uint64_t)Z_LVAL(zp[1]) - prev;
    /* Affine probe first: one subtract and compare per element with an early
     * break. The byte-sizing pass below costs more than an affine run saves,
     * so it runs only once affine is ruled out. */
    int affine = 1;
    for (uint32_t i = 1; i < n; i++) {
        uint64_t cur = (uint64_t)Z_LVAL(zp[i]);
        if (cur - prev != step0) { affine = 0; break; }
        prev = cur;
    }
    if (affine
        && n <= PHPSER_SUBLINEAR_MAX_ELEMS
        && e->sublinear_elems <= PHPSER_SUBLINEAR_MAX_ELEMS - n) {
        e->sublinear_elems += n;
        *out_step = (int64_t)step0;
        return TAG_PACKED_AFFINE;
    }
    prev = (uint64_t)Z_LVAL(zp[0]);
    uint32_t plain_bytes = varint_len_u64(zigzag_encode64((int64_t)prev));
    uint32_t delta_bytes = plain_bytes;
    for (uint32_t i = 1; i < n; i++) {
        uint64_t cur = (uint64_t)Z_LVAL(zp[i]);
        plain_bytes += varint_len_u64(zigzag_encode64((int64_t)cur));
        delta_bytes += varint_len_u64(zigzag_encode64((int64_t)(cur - prev)));
        prev = cur;
    }
    if (delta_bytes < plain_bytes) return TAG_PACKED_DELTA;
    return TAG_PACKED_LONGS;
}

static uint8_t detect_packed_run(encode_ctx *e, HashTable *ht, uint32_t n_used,
                                 int64_t *out_step) {
    zval *zp = ht->arPacked;
    switch (Z_TYPE(zp[0])) {
        case IS_LONG:
            for (uint32_t i = 1; i < n_used; i++) {
                if (Z_TYPE(zp[i]) != IS_LONG) return TAG_PACKED_MIXED;
            }
            return enc_pick_long_run_tag(e, zp, n_used, out_step);
        case IS_DOUBLE:
            for (uint32_t i = 1; i < n_used; i++) {
                if (Z_TYPE(zp[i]) != IS_DOUBLE) return TAG_PACKED_MIXED;
            }
            return TAG_PACKED_DOUBLES;
        default:
            return TAG_PACKED_MIXED;
    }
}

/* Hooks can mutate shared SPL storage through refcount-blind C APIs.
 * Walk a private copy for shared nested arrays; scalar runs need none.
 * ht_shared is measured before the caller's protective addref. Keep the
 * top-level argument unchanged to preserve object lifetime and identity. */
static zend_always_inline HashTable *enc_pin_walk(encode_ctx *e, HashTable *ht,
                                                  bool ht_shared, HashTable **dup) {
    if (ht_shared && e->depth > 1) {
        *dup = zend_array_dup(ht);
        return *dup;
    }
    *dup = NULL;
    return ht;
}

static void encode_hashtable(smart_str *body, encode_ctx *e, HashTable *ht,
                             bool in_rcn_array, bool ht_shared) {
    uint32_t n_used = ht->nNumUsed;
    uint32_t n_elems = ht->nNumOfElements;
    int is_packed = HT_IS_PACKED(ht);

    if (is_packed && n_used == n_elems && n_used > 0) {
        zval *zp = ht->arPacked;
        if (Z_TYPE(zp[0]) == IS_STRING) {
            /* Emit dictionary indices while checking eligibility. On a miss,
             * replace the tag and truncate the partial run; both forms share the
             * element count. No other append may invalidate base during this loop. */
            size_t tag_off = body->s ? ZSTR_LEN(body->s) : 0;
            smart_str_appendc(body, TAG_PACKED_STRINGS);
            varint_write_u64(body, n_elems);
            smart_str_alloc(body, (size_t)n_used * VARINT_MAX_BYTES, 0);
            char *base = ZSTR_VAL(body->s);
            size_t pos = ZSTR_LEN(body->s);
            const size_t run_start = pos;
            for (uint32_t i = 0; i < n_used; i++) {
                uint32_t idx;
                if (Z_TYPE(zp[i]) != IS_STRING
                    || !enc_str_dict_idx(e, Z_STR(zp[i]), &idx)) {
                    ZSTR_VAL(body->s)[tag_off] = (char)TAG_PACKED_MIXED;
                    ZSTR_LEN(body->s) = run_start;
                    HashTable *dup;
                    zval *wzp = enc_pin_walk(e, ht, ht_shared, &dup)->arPacked;
                    for (uint32_t j = 0; j < n_used; j++) {
                        encode_value_ex(body, e, &wzp[j], in_rcn_array);
                    }
                    if (dup) zend_array_destroy(dup);
                    return;
                }
                pos = varint_put(base, pos, idx);
            }
            ZSTR_LEN(body->s) = pos;
            return;
        }
        /* Dense packed, non-string lead: try a numeric typed-run tag. */
        int64_t affine_step = 0;
        uint8_t tag = detect_packed_run(e, ht, n_used, &affine_step);
        /* enc_try_table covers every homogeneous string-keyed rowset, including
         * all-MIXED columns, so TAG_ROWSET is never encoded. Its decoder stays
         * for older payloads. */
        if (tag == TAG_PACKED_MIXED && enc_try_table(body, e, zp, n_used)) {
            return;
        }
        if (tag == TAG_PACKED_AFFINE || tag == TAG_PACKED_DELTA) {
            e->wire_v2 = 1;
        }
        smart_str_appendc(body, tag);
        varint_write_u64(body, n_elems);
        if (tag == TAG_PACKED_AFFINE) {
            varint_write_i64(body, Z_LVAL(zp[0]));
            varint_write_i64(body, affine_step);
        } else if (tag == TAG_PACKED_DELTA) {
            smart_str_alloc(body, (size_t)n_used * VARINT_MAX_BYTES, 0);
            char *base = ZSTR_VAL(body->s);
            size_t pos = ZSTR_LEN(body->s);
            uint64_t prev = (uint64_t)Z_LVAL(zp[0]);
            pos = varint_put(base, pos, zigzag_encode64((int64_t)prev));
            for (uint32_t i = 1; i < n_used; i++) {
                uint64_t cur = (uint64_t)Z_LVAL(zp[i]);
                pos = varint_put(base, pos, zigzag_encode64((int64_t)(cur - prev)));
                prev = cur;
            }
            ZSTR_LEN(body->s) = pos;
        } else if (tag == TAG_PACKED_LONGS) {
            /* Reserve the run's worst case once, then write raw. Nothing else
             * appends to body inside the loop, so the cached base stays valid. */
            smart_str_alloc(body, (size_t)n_used * VARINT_MAX_BYTES, 0);
            char *base = ZSTR_VAL(body->s);
            size_t pos = ZSTR_LEN(body->s);
            for (uint32_t i = 0; i < n_used; i++) {
                pos = varint_put(base, pos, zigzag_encode64(Z_LVAL(zp[i])));
            }
            ZSTR_LEN(body->s) = pos;
        } else if (tag == TAG_PACKED_DOUBLES) {
            smart_str_alloc(body, (size_t)n_used * 8, 0);
            char *base = ZSTR_VAL(body->s);
            size_t pos = ZSTR_LEN(body->s);
            for (uint32_t i = 0; i < n_used; i++) {
                pos = le64_put(base, pos, Z_DVAL(zp[i]));
            }
            ZSTR_LEN(body->s) = pos;
        } else {
            HashTable *dup;
            zval *wzp = enc_pin_walk(e, ht, ht_shared, &dup)->arPacked;
            for (uint32_t i = 0; i < n_used; i++) {
                encode_value_ex(body, e, &wzp[i], in_rcn_array);
            }
            if (dup) zend_array_destroy(dup);
        }
        return;
    }

    if (is_packed) {
        /* Sparse packed (post-unset). Preserve original int keys. */
        smart_str_appendc(body, TAG_ASSOC);
        varint_write_u64(body, n_elems);
        HashTable *dup;
        zval *zp = enc_pin_walk(e, ht, ht_shared, &dup)->arPacked;
        for (uint32_t i = 0; i < n_used; i++) {
            if (Z_TYPE(zp[i]) == IS_UNDEF) continue;
            smart_str_appendc(body, KEY_LONG);
            varint_write_i64(body, (int64_t)i);
            encode_value_ex(body, e, &zp[i], in_rcn_array);
        }
        if (dup) zend_array_destroy(dup);
        return;
    }

    /* Non-packed (assoc). Try wire-v2 dict-only keys when every live entry is a
     * string key already in the dict (row 2+ of a rowset qualifies once row 1's
     * enc_emit_str_key calls upgraded the field names). */
    {
        Bucket *b = ht->arData;
        Bucket *end = b + n_used;
        int dict_keys = 1;
        uint32_t dummy_idx;
        for (; b < end; b++) {
            if (Z_TYPE(b->val) == IS_UNDEF) continue;
            if (!b->key || !enc_str_dict_idx(e, b->key, &dummy_idx)) {
                dict_keys = 0;
                break;
            }
        }
        if (dict_keys && n_elems > 0) {
            e->wire_v2 = 1;
            smart_str_appendc(body, TAG_ASSOC_DICT);
            varint_write_u64(body, n_elems);
            smart_str_alloc(body, (size_t)n_elems * VARINT_MAX_BYTES, 0);
            char *base = ZSTR_VAL(body->s);
            size_t pos = ZSTR_LEN(body->s);
            b = ht->arData;
            for (; b < end; b++) {
                if (Z_TYPE(b->val) == IS_UNDEF) continue;
                uint32_t kidx = 0;
                (void)enc_str_dict_idx(e, b->key, &kidx);
                pos = varint_put(base, pos, kidx);
            }
            ZSTR_LEN(body->s) = pos;
            /* Keys were emitted before hooks could run. Duplicate shared value
             * tables to isolate mutation; use the duplicate's nNumUsed because
             * zend_array_dup compacts holes while preserving order. */
            HashTable *dup;
            HashTable *vwht = enc_pin_walk(e, ht, ht_shared, &dup);
            Bucket *vb = vwht->arData;
            Bucket *vend = vb + vwht->nNumUsed;
            for (; vb < vend; vb++) {
                if (Z_TYPE(vb->val) == IS_UNDEF) continue;
                encode_value_ex(body, e, &vb->val, in_rcn_array);
            }
            if (dup) zend_array_destroy(dup);
            return;
        }
        smart_str_appendc(body, TAG_ASSOC);
        varint_write_u64(body, n_elems);
        /* zend_array_dup compacts an assoc table's UNDEF holes, so iterate the
         * walked table's own nNumUsed rather than the original's. */
        HashTable *dup;
        HashTable *awht = enc_pin_walk(e, ht, ht_shared, &dup);
        b = awht->arData;
        Bucket *wend = b + awht->nNumUsed;
        for (; b < wend; b++) {
            if (Z_TYPE(b->val) == IS_UNDEF) continue;
            if (b->key) {
                enc_emit_str_key(body, e, b->key);
            } else {
                emit_tag_and_varint(
                    body, KEY_LONG,
                    zigzag_encode64((int64_t)(zend_long)b->h));
            }
            encode_value_ex(body, e, &b->val, in_rcn_array);
        }
        if (dup) zend_array_destroy(dup);
    }
}

enum { DEFER_UNSERIALIZE, DEFER_WAKEUP };

typedef struct {
    uint8_t kind;
    zend_object *obj;
    zval data;
} deferred_event;

/* Store GC entities, not zval pointers: HashTable growth can move zval slots. */
enum { ID_OBJ, ID_REF, ID_NULL };

typedef struct {
    uint8_t kind;
    uint8_t pinned; /* 1 if id_table holds an extra GC refcount on u */
    union {
        zend_object    *obj;
        zend_reference *ref;
    } u;
} id_slot;

typedef struct {
    const uint8_t *buf;
    size_t len;
    size_t pos;
    /* Own strings until decode teardown; consumers take independent references. */
    zend_string **dict;
    uint32_t dict_len;
    /* Encounter ids retain GC entities until decode_destroy; duplicate-key
     * overwrites must not invalidate later back-references. */
    id_slot *id_table;
    uint32_t id_table_len;
    uint32_t id_table_cap;
    deferred_event *deferred;
    uint32_t deferred_len;
    uint32_t deferred_cap;
    /* The caller owns allowed_set, keyed by lowercase class names. Denied classes
     * become __PHP_Incomplete_Class. */
    int allowed_mode;
    HashTable *allowed_set;
    /* Cache successful class lookups by dictionary index. Do not cache misses:
     * an autoloader can make the class available later in the same graph. */
    zend_class_entry **ce_cache;
    /* Per-dict-index decisions: 0 unknown, 1 allowed, 2 denied. Allocated lazily
     * for ALLOWED_SET to avoid repeated lowercasing and allowlist lookups. */
    uint8_t *allow_cache;
    /* Class-name validity cache: (class_idx → 0 unchecked / 1 valid). Sized
     * to dict_len, lazy-allocated on first class-carrying tag. Invalid names
     * abort the decode, so only "valid" is ever stored. */
    uint8_t *cname_cache;
    /* C-stack recursion guard for container tags that recurse through
     * decode_value. Uncapped, a crafted payload blows the pthread stack at
     * ~100K nested frames. */
    uint32_t depth;
    /* Elements already materialized under sub-linear tags; see
     * PHPSER_SUBLINEAR_MAX_ELEMS. */
    uint32_t sublinear_elems;
} decode_ctx;

/* Zend's string hash is deliberately stable and therefore craftable. Bound
 * every wire-controlled bucket walk before it becomes quadratic. Normal
 * tables almost never reach this chain length, and packed arrays never touch
 * arHash. */
#define MAX_HASH_CHAIN_LENGTH 64
static zend_always_inline int dec_hash_chain_exhausted(
    HashTable *ht, zend_ulong h)
{
    if (EXPECTED(ht->nNumOfElements < MAX_HASH_CHAIN_LENGTH)
        || HT_IS_PACKED(ht)) {
        return 0;
    }

    uint32_t idx = HT_HASH(ht, (uint32_t)h | ht->nTableMask);
    uint32_t remaining = MAX_HASH_CHAIN_LENGTH;
    while (idx != HT_INVALID_IDX) {
        if (UNEXPECTED(--remaining == 0)) return 1;
        Bucket *bucket = HT_HASH_TO_BUCKET(ht, idx);
        idx = Z_NEXT(bucket->val);
    }
    return 0;
}

static zend_always_inline int dec_string_hash_chain_exhausted(
    HashTable *ht, zend_string *key)
{
    return dec_hash_chain_exhausted(ht, zend_string_hash_val(key));
}

/* Claim ids in the encoder's encounter order, before decoding children.
 * Every id-claiming tag must register here; TAG_NULL claims none.
 * Pin objects and references until decode_destroy: overwriting a duplicate
 * key can drop the graph's last reference before a later TAG_REF uses it. */
static void dec_register(decode_ctx *d, zval *z) {
    if (d->id_table_len == d->id_table_cap) {
        d->id_table_cap = d->id_table_cap ? d->id_table_cap * 2 : 16;
        d->id_table = erealloc(d->id_table, d->id_table_cap * sizeof(id_slot));
    }
    id_slot *s = &d->id_table[d->id_table_len++];
    s->pinned = 0;
    if (Z_TYPE_P(z) == IS_OBJECT) {
        s->kind = ID_OBJ;
        s->u.obj = Z_OBJ_P(z);
        /* Signed frames also need pins: a valid HMAC does not prove key uniqueness. */
        GC_ADDREF(s->u.obj);
        s->pinned = 1;
    } else if (Z_TYPE_P(z) == IS_REFERENCE) {
        s->kind = ID_REF;
        s->u.ref = Z_REF_P(z);
        GC_ADDREF(s->u.ref);
        s->pinned = 1;
    } else {
        /* The encoder claimed an id here; register a NULL slot to keep ids
         * aligned. Back-refs to it yield NULL. */
        s->kind = ID_NULL;
    }
}

/* Queue a deferred magic-method event. Holds a ref on `obj` until
 * decode_destroy: hooks run user PHP that can drop the last live reference
 * to a sibling still queued later in the list (UAF without the pin).
 * For DEFER_UNSERIALIZE, `data` ownership transfers into the event; for
 * DEFER_WAKEUP pass NULL and the data slot is left UNDEF. */
static void dec_defer_push(decode_ctx *d, zend_object *obj, uint8_t kind, zval *data) {
    if (d->deferred_len == d->deferred_cap) {
        d->deferred_cap = d->deferred_cap ? d->deferred_cap * 2 : 4;
        d->deferred = erealloc(d->deferred,
            d->deferred_cap * sizeof(deferred_event));
    }
    GC_ADDREF(obj);
    d->deferred[d->deferred_len].kind = kind;
    d->deferred[d->deferred_len].obj = obj;
    if (data) {
        ZVAL_COPY_VALUE(&d->deferred[d->deferred_len].data, data);
    } else {
        ZVAL_UNDEF(&d->deferred[d->deferred_len].data);
    }
    d->deferred_len++;
}

static void dec_defer_unserialize(decode_ctx *d, zend_object *obj, zval *data) {
    /* PHP's COW separates the $data param so deferred[i].data stays safe. */
    dec_defer_push(d, obj, DEFER_UNSERIALIZE, data);
}

static void dec_defer_wakeup(decode_ctx *d, zend_object *obj) {
    dec_defer_push(d, obj, DEFER_WAKEUP, NULL);
}

static void dec_maybe_defer_wakeup(decode_ctx *d, zend_class_entry *ce, zend_object *obj) {
    if (zend_hash_find_known_hash(&ce->function_table,
            ZSTR_KNOWN(ZEND_STR_WAKEUP)) != NULL) {
        dec_defer_wakeup(d, obj);
    }
}

/* object_init_ex must not run on these: NOT_SERIALIZABLE yields a corrupt
 * instance; UNINSTANTIABLE throws past the decoder's return-NULL contract. */
static zend_always_inline int dec_ce_uninstantiable(zend_class_entry *ce) {
    return (ce->ce_flags & (ZEND_ACC_NOT_SERIALIZABLE | ZEND_ACC_UNINSTANTIABLE)) != 0;
}

/* Callers must bound class_idx via dec_get_zstr first. Cache only successes:
 * autoload may make a missing class available later. */
static inline zend_class_entry *dec_class_resolve_ex(
    decode_ctx *d, uint64_t class_idx, zend_string *class_name, int autoload)
{
    ZEND_ASSERT(class_idx < d->dict_len);
    if (UNEXPECTED(!d->ce_cache)) {
        d->ce_cache = ecalloc(d->dict_len, sizeof(zend_class_entry *));
    }
    zend_class_entry *ce = d->ce_cache[class_idx];
    if (EXPECTED(ce != NULL)) {
        return ce;
    }
    ce = zend_lookup_class_ex(class_name, NULL,
                              autoload ? 0 : ZEND_FETCH_CLASS_NO_AUTOLOAD);
    if (ce) {
        d->ce_cache[class_idx] = ce;
    }
    return ce;
}
static inline zend_class_entry *dec_class_resolve(
    decode_ctx *d, uint64_t class_idx, zend_string *class_name)
{
    return dec_class_resolve_ex(d, class_idx, class_name, /* autoload */ 1);
}

/* Returns 1 if `class_name` (dict slot class_idx) is allowed by the current
 * decode_ctx filter. The set is pre-lowercased on caller side; the decision
 * is memoized per class_idx so a repeated class name in a DTO batch pays the
 * tolower + lookup once. class_idx < dict_len is a precondition (every caller
 * resolves class_name via dec_get_zstr first). */
static inline int dec_class_allowed(decode_ctx *d, uint64_t class_idx,
                                    zend_string *class_name) {
    if (EXPECTED(d->allowed_mode == ALLOWED_ALL)) return 1;
    if (d->allowed_mode == ALLOWED_NONE) return 0;
    /* ALLOWED_SET: case-insensitive lookup. PHP class names are stored
     * lowercased in the engine class table, and user-supplied names in
     * allowed_classes get pre-lowercased into d->allowed_set. */
    if (UNEXPECTED(!d->allow_cache)) {
        d->allow_cache = ecalloc(d->dict_len, sizeof(uint8_t));
    }
    uint8_t cached = d->allow_cache[class_idx];
    if (EXPECTED(cached != 0)) return cached == 1;
    zend_string *lc = zend_string_tolower(class_name);
    int ok = zend_hash_exists(d->allowed_set, lc);
    zend_string_release(lc);
    d->allow_cache[class_idx] = ok ? 1 : 2;
    return ok;
}

/* Write a decoded value into a declared property slot (typed or untyped).
 * Takes ownership of *tmp: on success it's moved into the slot; on
 * type-mismatch it's dtor'd. Returns 0/-1. */
static int dec_install_declared_slot(zend_object *obj, zend_property_info *info,
                                     zval *tmp) {
    zval *slot = OBJ_PROP(obj, info->offset);
    if (ZEND_TYPE_IS_SET(info->type)) {
        /* Scalar mask hits need no engine call. Objects must still verify class
         * constraints; an IS_OBJECT mask hit alone accepts the wrong class. */
        if (!(!Z_ISREF_P(tmp)
                && Z_TYPE_P(tmp) != IS_OBJECT
                && EXPECTED(ZEND_TYPE_CONTAINS_CODE(info->type, Z_TYPE_P(tmp))))
            && !zend_verify_prop_assignable_by_ref(info, tmp, /*strict*/ 1)) {
            zval_ptr_dtor(tmp);
            return -1;
        }
        if (Z_ISREF_P(slot)) {
            ZEND_REF_DEL_TYPE_SOURCE(Z_REF_P(slot), info);
        }
        zval_ptr_dtor(slot);
        ZVAL_COPY_VALUE(slot, tmp);
        if (Z_ISREF_P(slot)) {
            ZEND_REF_ADD_TYPE_SOURCE(Z_REF_P(slot), info);
        }
    } else {
        zval_ptr_dtor(slot);
        ZVAL_COPY_VALUE(slot, tmp);
    }
    return 0;
}

/* Install one decoded value as a property on `obj`. Mirrors TAG_OBJECT's
 * IS_INDIRECT-vs-dynamic dispatch with typed-slot checking. Used by both
 * TAG_OBJECT and TAG_OBJECT_MAGIC's __unserialize-missing fallback.
 * Takes ownership of *tmp. Returns 0/-1. */
static int dec_install_prop(zend_object *obj, HashTable *obj_props,
                            zend_string *key, zval *tmp) {
    /* Reserve the original class-name marker: a wire overwrite could resurrect
     * another class after reserialization. The encoder omits this marker; rejecting
     * the overwrite is stricter than native. */
    if (UNEXPECTED(obj->ce == PHP_IC_ENTRY)
        && zend_string_equals_literal(key, MAGIC_MEMBER)) {
        zval_ptr_dtor(tmp);
        return 0;
    }
    if (UNEXPECTED(dec_string_hash_chain_exhausted(obj_props, key))) {
        zval_ptr_dtor(tmp);
        return -1;
    }
    zval *existing = zend_hash_find(obj_props, key);
    if (existing) {
        if (Z_TYPE_P(existing) == IS_INDIRECT) {
            zval *slot = Z_INDIRECT_P(existing);
            zend_property_info *info =
                zend_get_typed_property_info_for_slot(obj, slot);
            if (info != NULL) {
                if (!zend_verify_prop_assignable_by_ref(
                        info, tmp, /*strict*/ 1)) {
                    zval_ptr_dtor(tmp);
                    return -1;
                }
                if (Z_ISREF_P(slot)) {
                    ZEND_REF_DEL_TYPE_SOURCE(Z_REF_P(slot), info);
                }
            }
            zval_ptr_dtor(slot);
            ZVAL_COPY_VALUE(slot, tmp);
            if (info != NULL && Z_ISREF_P(slot)) {
                ZEND_REF_ADD_TYPE_SOURCE(Z_REF_P(slot), info);
            }
        } else {
            zval_ptr_dtor(existing);
            ZVAL_COPY_VALUE(existing, tmp);
        }
    } else {
        if (UNEXPECTED(obj->ce->ce_flags & ZEND_ACC_NO_DYNAMIC_PROPERTIES)) {
            zend_throw_error(NULL, "Cannot create dynamic property %s::$%s",
                ZSTR_VAL(obj->ce->name),
                zend_get_unmangled_property_name(key));
            zval_ptr_dtor(tmp);
            return -1;
        }
        if (!(obj->ce->ce_flags & ZEND_ACC_ALLOW_DYNAMIC_PROPERTIES)) {
            zend_error(E_DEPRECATED, "Creation of dynamic property %s::$%s is deprecated",
                ZSTR_VAL(obj->ce->name),
                zend_get_unmangled_property_name(key));
            if (UNEXPECTED(EG(exception))) {
                zval_ptr_dtor(tmp);
                return -1;
            }
        }
        zend_hash_add_new(obj_props, key, tmp);
    }
    return 0;
}

/* Use direct slots only when equivalent to the materialized HashTable path.
 * properties_info uses plain names, but info->name must match the mangled wire key
 * exactly. Dynamic, static, virtual, or mismatched private properties require
 * fallback. */
static zend_always_inline zend_property_info *dec_prop_info_for_key(
    zend_class_entry *ce, zend_string *key)
{
    zend_property_info *info;
    if (EXPECTED(ZSTR_LEN(key) > 0 && ZSTR_VAL(key)[0] != '\0')) {
        info = zend_hash_find_ptr(&ce->properties_info, key);
        if (!info) return NULL;
        if (UNEXPECTED(!zend_string_equals(info->name, key))) return NULL;
    } else {
        const char *cls_name, *prop_name;
        size_t prop_len;
        if (zend_unmangle_property_name_ex(key, &cls_name, &prop_name,
                                           &prop_len) != SUCCESS) {
            return NULL;
        }
        info = zend_hash_str_find_ptr(&ce->properties_info, prop_name, prop_len);
        if (!info) return NULL;
        if (!zend_string_equals(info->name, key)) return NULL;
    }
    if (UNEXPECTED(info->flags & ZEND_ACC_STATIC)) return NULL;
#if PHP_VERSION_ID >= 80400
    if (UNEXPECTED(info->offset == (uint32_t)ZEND_VIRTUAL_PROPERTY_OFFSET)) {
        return NULL;
    }
#endif
    return info;
}

/* Reject forbidden dynamic properties before decoding values, which could autoload
 * classes. Returns 1 with a pending Error; dec_install_prop also guards its other
 * callers. */
static zend_always_inline int dec_dynamic_prop_forbidden(
    zend_object *obj, HashTable *obj_props, zend_string *key) {
    if (EXPECTED(!(obj->ce->ce_flags & ZEND_ACC_NO_DYNAMIC_PROPERTIES))) {
        return 0;
    }
    /* Declared slot (or an already-present key) is not a new dynamic prop. */
    if (obj_props == NULL) {
        if (dec_prop_info_for_key(obj->ce, key) != NULL) return 0;
    } else if (zend_hash_find(obj_props, key) != NULL) {
        return 0;
    }
    zend_throw_error(NULL, "Cannot create dynamic property %s::$%s",
        ZSTR_VAL(obj->ce->name), zend_get_unmangled_property_name(key));
    return 1;
}

/* Without __unserialize, apply data as properties, including to incomplete classes.
 * Integer keys become string-named dynamic properties. */
static int dec_apply_data_as_props(zend_object *obj, HashTable *data_ht) {
    HashTable *obj_props = zend_std_get_properties(obj);
    zend_string *key;
    zend_ulong h;
    zval *val;
    ZEND_HASH_FOREACH_KEY_VAL(data_ht, h, key, val) {
        zval tmp;
        ZVAL_COPY(&tmp, val);  /* addref for the new owner */
        if (key) {
            /* A typed-slot mismatch leaves a pending TypeError; fail the decode
             * instead of installing more props and queueing __wakeup under it. */
            if (dec_install_prop(obj, obj_props, key, &tmp) < 0) return -1;
        } else {
            /* Int keys become string-named dynamic properties ("0", "1"), as
             * in PHP. */
            zend_string *str_key = zend_long_to_str((zend_long)h);
            int rc = dec_install_prop(obj, obj_props, str_key, &tmp);
            zend_string_release(str_key);
            if (rc < 0) return -1;
        }
    } ZEND_HASH_FOREACH_END();
    return 0;
}

/* Build an __PHP_Incomplete_Class instance with the original class name
 * stored in the magic property. The caller decodes any subsequent props
 * into this object; they land alongside the magic name. */
static int dec_make_incomplete(zval *out, zend_string *original_class_name) {
    if (object_init_ex(out, PHP_IC_ENTRY) != SUCCESS) {
        ZVAL_NULL(out);
        return -1;
    }
    php_store_class_name(out, original_class_name);
    return 0;
}

/* Bound-check the uint64 index BEFORE narrowing; otherwise idx = 2^32 (or any
 * multiple of dict_len) truncates onto a valid slot. */
static inline zend_string *dec_get_zstr(decode_ctx *d, uint64_t idx) {
    if (UNEXPECTED(idx >= d->dict_len)) return NULL;
    return d->dict[idx];
}

static inline zend_string *dec_get_class_name(decode_ctx *d, uint64_t idx) {
    zend_string *name = dec_get_zstr(d, idx);
    if (!name) return NULL;
    /* Memoize per dict slot: same-class batches (dto rowsets) hit this once
     * per object tag, and zend_is_valid_class_name walks the whole string.
     * Invalid names abort the decode, so only validity needs caching. */
    if (UNEXPECTED(!d->cname_cache)) {
        d->cname_cache = ecalloc(d->dict_len, sizeof(uint8_t));
    }
    if (EXPECTED(d->cname_cache[idx])) return name;
    if (ZSTR_LEN(name) == 0 || ZSTR_VAL(name)[0] == '\\'
        || !zend_is_valid_class_name(name)) {
        return NULL;
    }
    d->cname_cache[idx] = 1;
    return name;
}

static int decode_value(decode_ctx *d, zval *out);
static zend_string **dec_read_schema_keys(decode_ctx *d, uint64_t nkeys, int *use_add_new);
/* Returns 1 for unique, 0 for duplicates, -1 when the collision budget is
 * exhausted. */
static int dec_schema_keys_are_unique(zend_string **keys, uint64_t nkeys);
static zend_never_inline int dec_decode_table(decode_ctx *d, zval *out);
static zend_never_inline int dec_decode_rowset(decode_ctx *d, zval *out);

/* Eagerly materialize every dict slot with a precomputed hash: one less branch
 * in the per-string hot path, and zend_hash_add_new skips hashing. */
static int decode_header(decode_ctx *d) {
    if (d->len < 1) return -1;
    uint8_t ver = d->buf[d->pos++];
    if (ver != PHPSER_VERSION && ver != PHPSER_VERSION_V2) return -1;
    uint64_t n;
    if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
    /* Even an empty dict entry consumes a one-byte length varint. Bound N by
     * remaining input before allocating, and reject UINT32_MAX explicitly so
     * the allocation count can never wrap in size arithmetic. */
    if (n >= UINT32_MAX || n > d->len - d->pos) return -1;
    d->dict_len = (uint32_t)n;
    d->dict = ecalloc((size_t)d->dict_len + 1, sizeof(zend_string *));
    for (uint32_t i = 0; i < d->dict_len; i++) {
        uint64_t slen;
        if (varint_read_u64(d->buf, d->len, &d->pos, &slen) < 0) return -1;
        if (slen > UINT32_MAX || slen > d->len - d->pos) return -1;
        /* Reuse engine-interned names to avoid allocations and refcounts on common
         * keys. Both the interned and allocated paths supply a precomputed hash. */
        d->dict[i] = zend_string_init_existing_interned(
            (const char *)(d->buf + d->pos), (size_t)slen, 0);
        d->pos += slen;
    }
    return 0;
}

static void decode_destroy(decode_ctx *d) {
    if (d->dict) {
        for (uint32_t i = 0; i < d->dict_len; i++) {
            if (d->dict[i]) zend_string_release(d->dict[i]);
        }
        efree(d->dict);
    }
    if (d->id_table) {
        /* Release the refcount we took at registration. ID_NULL slots
         * own nothing; ID_OBJ / ID_REF release via the type-specific
         * macro so destructors and ref-table teardown fire correctly. */
        for (uint32_t i = 0; i < d->id_table_len; i++) {
            id_slot *s = &d->id_table[i];
            if (!s->pinned) continue;
            if (s->kind == ID_OBJ) {
                OBJ_RELEASE(s->u.obj);
            } else if (s->kind == ID_REF) {
                GC_DTOR(s->u.ref);
            }
        }
        efree(d->id_table);
    }
    if (d->deferred) {
        for (uint32_t i = 0; i < d->deferred_len; i++) {
            OBJ_RELEASE(d->deferred[i].obj);
            if (d->deferred[i].kind == DEFER_UNSERIALIZE) {
                zval_ptr_dtor(&d->deferred[i].data);
            }
        }
        efree(d->deferred);
    }
    if (d->ce_cache) efree(d->ce_cache);
    if (d->allow_cache) efree(d->allow_cache);
    if (d->cname_cache) efree(d->cname_cache);
}

/* Assoc key: int, dict-indexed string, or owned inline string. The owned
 * case allocates a fresh zend_string; the caller releases it after the
 * destination table takes its own reference. Returns 0/-1. */
enum { KV_LONG, KV_DICT_STR, KV_OWNED_STR };

typedef struct {
    int kind;
    int64_t lval;            /* KV_LONG */
    zend_string *str;        /* string key; refcount=1 only for KV_OWNED_STR */
} key_val;

static zend_always_inline int dec_i64_fits_zend_long(int64_t value) {
#if SIZEOF_ZEND_LONG == 4
    return value >= ZEND_LONG_MIN && value <= ZEND_LONG_MAX;
#else
    return 1;
#endif
}

static int decode_key(decode_ctx *d, key_val *out_key) {
    if (d->pos >= d->len) return -1;
    uint8_t tag = d->buf[d->pos++];
    if (tag == KEY_LONG) {
        int64_t value;
        if (varint_read_i64(d->buf, d->len, &d->pos, &value) < 0
            || UNEXPECTED(!dec_i64_fits_zend_long(value))) {
            return -1;
        }
        out_key->kind = KV_LONG;
        out_key->lval = value;
        return 0;
    }
    if (tag == KEY_STR) {
        uint64_t idx;
        if (varint_read_u64(d->buf, d->len, &d->pos, &idx) < 0) return -1;
        zend_string *zs = dec_get_zstr(d, idx);
        if (!zs) return -1;
        out_key->kind = KV_DICT_STR;
        out_key->str = zs;
        return 0;
    }
    if (tag == KEY_STR_INLINE) {
        uint64_t slen;
        if (varint_read_u64(d->buf, d->len, &d->pos, &slen) < 0) return -1;
        if (slen > UINT32_MAX || slen > d->len - d->pos) return -1;
        out_key->kind = KV_OWNED_STR;
        out_key->str = zend_string_init(
            (const char *)d->buf + d->pos, (size_t)slen, 0);
        d->pos += slen;
        return 0;
    }
    return -1;
}

static int decode_value_inner(decode_ctx *d, zval *out);

/* Scalar tags are the fixed-width and dict-ref leaves. Container tags
 * 0x06-0x0b sit between TAG_DOUBLE and TAG_STR_INLINE, so a plain
 * `tag <= TAG_STR_INLINE` range check would misclassify them. */
static zend_always_inline int dec_is_scalar_tag(uint8_t tag) {
    return tag <= TAG_DOUBLE || tag == TAG_STR_DICT || tag == TAG_STR_INLINE;
}

/* Decode a tag whose leading byte was already consumed. Caller must only pass
 * scalar tags; returns -1 on truncation. */
static int decode_scalar_tag(decode_ctx *d, zval *out, uint8_t tag) {
    switch (tag) {
        case TAG_NULL:  ZVAL_NULL(out); return 0;
        case TAG_FALSE: ZVAL_FALSE(out); return 0;
        case TAG_TRUE:  ZVAL_TRUE(out); return 0;
        case TAG_LONG: {
            int64_t v;
            if (varint_read_i64(d->buf, d->len, &d->pos, &v) < 0
                || UNEXPECTED(!dec_i64_fits_zend_long(v))) {
                return -1;
            }
            ZVAL_LONG(out, v);
            return 0;
        }
        case TAG_DOUBLE: {
            if (d->pos + 8 > d->len) return -1;
            ZVAL_DOUBLE(out, le64_read(d->buf + d->pos));
            d->pos += 8;
            return 0;
        }
        case TAG_STR_DICT: {
            uint64_t idx;
            if (varint_read_u64(d->buf, d->len, &d->pos, &idx) < 0) return -1;
            zend_string *zs = dec_get_zstr(d, idx);
            if (!zs) return -1;
            ZVAL_STR_COPY(out, zs);
            return 0;
        }
        case TAG_STR_INLINE: {
            uint64_t slen;
            if (varint_read_u64(d->buf, d->len, &d->pos, &slen) < 0) return -1;
            if (slen > UINT32_MAX || slen > d->len - d->pos) return -1;
            zend_string *zs = zend_string_init(
                (const char *)d->buf + d->pos, (size_t)slen, 0);
            d->pos += slen;
            ZVAL_STR(out, zs);
            return 0;
        }
        default:
            return -1;
    }
}

static zend_always_inline void dec_finish_packed(zend_array *arr, uint64_t n, zval *out) {
    arr->nNumUsed = (uint32_t)n;
    arr->nNumOfElements = (uint32_t)n;
    arr->nNextFreeElement = (zend_long)n;
    ZVAL_ARR(out, arr);
}

/* Hot-loop helper: scalars skip the decode_value wrapper since they never
 * recurse and don't need a depth bump. Container tags rewind one byte and
 * fall back to the full path. */
static zend_always_inline int decode_value_hot(decode_ctx *d, zval *out) {
    if (UNEXPECTED(d->pos >= d->len)) {
        ZVAL_UNDEF(out);
        return -1;
    }
    uint8_t tag = d->buf[d->pos++];
    if (EXPECTED(dec_is_scalar_tag(tag))) {
        ZVAL_UNDEF(out);
        int rc = decode_scalar_tag(d, out, tag);
        if (UNEXPECTED(rc < 0)) {
            ZVAL_UNDEF(out);
        }
        return rc;
    }
    d->pos--;
    return decode_value(d, out);
}

/* Update a fresh, pre-sized TAG_ASSOC table while bounding the same bucket
 * walk zend_hash_update would perform internally. Returning -1 leaves `value`
 * owned by the caller. */
static zend_always_inline int dec_assoc_update_bounded(
    HashTable *ht, key_val *key, zval *value)
{
    zend_ulong h;
    zend_string *zs = NULL;
    if (key->kind == KV_LONG) {
        h = (zend_ulong)key->lval;
    } else if (!ZEND_HANDLE_NUMERIC(key->str, h)) {
        zs = key->str;
        h = zend_string_hash_val(zs);
    }

    uint32_t nIndex = (uint32_t)h | ht->nTableMask;
    uint32_t idx = HT_HASH(ht, nIndex);
    uint32_t chain_len = 0;
    while (idx != HT_INVALID_IDX) {
        Bucket *bucket = HT_HASH_TO_BUCKET(ht, idx);
        if (bucket->h == h
            && ((zs == NULL && bucket->key == NULL)
                || (zs != NULL && bucket->key != NULL
                    && zend_string_equals(bucket->key, zs)))) {
            zval_ptr_dtor(&bucket->val);
            ZVAL_COPY_VALUE(&bucket->val, value);
            return 0;
        }
        if (UNEXPECTED(++chain_len >= MAX_HASH_CHAIN_LENGTH)) return -1;
        idx = Z_NEXT(bucket->val);
    }

    uint32_t new_idx = ht->nNumUsed++;
    ht->nNumOfElements++;
    Bucket *bucket = ht->arData + new_idx;
    bucket->h = h;
    bucket->key = zs;
    if (zs != NULL && !ZSTR_IS_INTERNED(zs)) {
        zend_string_addref(zs);
        HT_FLAGS(ht) &= ~HASH_FLAG_STATIC_KEYS;
    }
    Z_NEXT(bucket->val) = HT_HASH(ht, nIndex);
    HT_HASH(ht, nIndex) = HT_IDX_TO_HASH(new_idx);
    if (zs == NULL && (zend_long)h >= ht->nNextFreeElement) {
        ht->nNextFreeElement = (zend_long)h < ZEND_LONG_MAX
            ? h + 1 : ZEND_LONG_MAX;
    }
    ZVAL_COPY_VALUE(&bucket->val, value);
    return 0;
}

/* Enforce MAX_DEPTH and leave *out IS_UNDEF on failure, destroying partial
 * values. The inner decoder must initialize *out before any error return. */
static int decode_value(decode_ctx *d, zval *out) {
    if (UNEXPECTED(d->depth >= MAX_DEPTH)) {
        ZVAL_UNDEF(out);
        return -1;
    }
    d->depth++;
    int rc = decode_value_inner(d, out);
    d->depth--;
    if (UNEXPECTED(rc < 0) && Z_TYPE_P(out) != IS_UNDEF) {
        zval_ptr_dtor(out);
        ZVAL_UNDEF(out);
    }
    return rc;
}

static int dec_table_column(decode_ctx *d, zval *col, uint64_t nrows, uint8_t col_tag) {
    uint64_t i = 0;
    switch (col_tag) {
        case TAG_PACKED_LONGS:
            for (; i < nrows; i++) {
                int64_t lv;
                if (varint_read_i64(d->buf, d->len, &d->pos, &lv) < 0
                    || UNEXPECTED(!dec_i64_fits_zend_long(lv))) {
                    goto fail;
                }
                ZVAL_LONG(&col[i], lv);
            }
            return 0;
        case TAG_PACKED_DOUBLES:
            if (nrows > (d->len - d->pos) / 8) goto fail;
            for (; i < nrows; i++) {
                if (d->pos + 8 > d->len) goto fail;
                ZVAL_DOUBLE(&col[i], le64_read(d->buf + d->pos));
                d->pos += 8;
            }
            return 0;
        case TAG_PACKED_STRINGS:
            for (; i < nrows; i++) {
                uint64_t idx;
                if (varint_read_u64(d->buf, d->len, &d->pos, &idx) < 0) goto fail;
                zend_string *zs = dec_get_zstr(d, idx);
                if (!zs) goto fail;
                ZVAL_STR_COPY(&col[i], zs);
            }
            return 0;
        case TAG_PACKED_DELTA: {
            uint64_t acc = 0;
            for (; i < nrows; i++) {
                int64_t dv;
                if (varint_read_i64(d->buf, d->len, &d->pos, &dv) < 0) goto fail;
                acc += (uint64_t)dv;
                if (UNEXPECTED(!dec_i64_fits_zend_long((int64_t)acc))) goto fail;
                ZVAL_LONG(&col[i], (zend_long)(int64_t)acc);
            }
            return 0;
        }
        case TAG_PACKED_MIXED:
            for (; i < nrows; i++) {
                if (decode_value_hot(d, &col[i]) < 0) goto fail;
            }
            return 0;
    }
fail:
    /* A partial column leaves cells [i, nrows) as uninitialized emalloc bytes.
     * Blank them so the caller's uniform zval_ptr_dtor over all nrows can't
     * release garbage. Only the failure path reaches this label. */
    for (; i < nrows; i++) {
        ZVAL_UNDEF(&col[i]);
    }
    return -1;
}

static int decode_value_inner(decode_ctx *d, zval *out) {
    /* Baseline so the wrapper's Z_TYPE_P(out) check on -1 is well-defined
     * regardless of whether the caller passed an initialized zval. */
    ZVAL_UNDEF(out);
    if (d->pos >= d->len) return -1;
    uint8_t tag = d->buf[d->pos++];
    switch (tag) {
        case TAG_REF: {
            uint64_t id;
            if (varint_read_u64(d->buf, d->len, &d->pos, &id) < 0) return -1;
            if (id >= d->id_table_len) return -1;
            id_slot *s = &d->id_table[id];
            switch (s->kind) {
                case ID_OBJ:  ZVAL_OBJ_COPY(out, s->u.obj); return 0;
                case ID_REF:  ZVAL_REF(out, s->u.ref); GC_ADDREF(s->u.ref); return 0;
                case ID_NULL: ZVAL_NULL(out); return 0;
            }
            return -1;
        }
        case TAG_NEW_REF: {
            /* Allocate the zend_reference now and register it BEFORE
             * recursing, so a back-ref inside the inner value resolves to
             * this very reference. */
            zend_reference *ref = (zend_reference *)emalloc(sizeof(zend_reference));
            GC_SET_REFCOUNT(ref, 1);
            GC_TYPE_INFO(ref) = GC_REFERENCE;
            ref->sources.ptr = NULL;
            ZVAL_UNDEF(&ref->val);
            ZVAL_REF(out, ref);
            dec_register(d, out);
            if (decode_value(d, &ref->val) < 0) return -1;
            /* PHP cannot produce a reference directly wrapping another reference.
             * Crafted nested/self references recurse during teardown; reject and
             * clear the inner edge so id-table cleanup can release it. Legitimate
             * cycles pass through arrays or objects and use cyclic GC. */
            if (Z_TYPE(ref->val) == IS_REFERENCE) {
                zval_ptr_dtor(&ref->val);
                ZVAL_UNDEF(&ref->val);
                return -1;
            }
            return 0;
        }
        case TAG_NULL:
        case TAG_FALSE:
        case TAG_TRUE:
        case TAG_LONG:
        case TAG_DOUBLE:
        case TAG_STR_DICT:
        case TAG_STR_INLINE:
            return decode_scalar_tag(d, out, tag);
        case TAG_PACKED_LONGS: {
            uint64_t n;
            if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
            /* Each element is at least one byte, so bound N by the remaining
             * buffer; otherwise N=2^32 OOMs before parsing fails. */
            if (n > UINT32_MAX || n > d->len - d->pos) return -1;
            /* Pre-sized HT + direct arPacked writes. */
            zend_array *arr = zend_new_array((uint32_t)n);
            zend_hash_real_init_packed(arr);
            for (uint64_t i = 0; i < n; i++) {
                int64_t lv;
                if (varint_read_i64(d->buf, d->len, &d->pos, &lv) < 0
                    || UNEXPECTED(!dec_i64_fits_zend_long(lv))) {
                    zend_array_destroy(arr);
                    return -1;
                }
                ZVAL_LONG(&arr->arPacked[i], lv);
            }
            dec_finish_packed(arr, n, out);
            return 0;
        }
        case TAG_PACKED_DOUBLES: {
            uint64_t n;
            if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
            /* PACKED_DOUBLES needs exactly 8*N bytes. */
            if (n > UINT32_MAX || n > (d->len - d->pos) / 8) return -1;
            zend_array *arr = zend_new_array((uint32_t)n);
            zend_hash_real_init_packed(arr);
            for (uint64_t i = 0; i < n; i++) {
                ZVAL_DOUBLE(&arr->arPacked[i], le64_read(d->buf + d->pos));
                d->pos += 8;
            }
            dec_finish_packed(arr, n, out);
            return 0;
        }
        case TAG_PACKED_STRINGS: {
            uint64_t n;
            if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
            if (n > UINT32_MAX || n > d->len - d->pos) return -1;
            zend_array *arr = zend_new_array((uint32_t)n);
            zend_hash_real_init_packed(arr);
            for (uint64_t i = 0; i < n; i++) {
                uint64_t idx;
                if (varint_read_u64(d->buf, d->len, &d->pos, &idx) < 0) {
                    arr->nNumUsed = (uint32_t)i;
                    zend_array_destroy(arr);
                    return -1;
                }
                zend_string *zs = dec_get_zstr(d, idx);
                if (!zs) {
                    arr->nNumUsed = (uint32_t)i;
                    zend_array_destroy(arr);
                    return -1;
                }
                ZVAL_STR_COPY(&arr->arPacked[i], zs);
            }
            dec_finish_packed(arr, n, out);
            return 0;
        }
        case TAG_PACKED_MIXED: {
            uint64_t n;
            if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
            if (n > UINT32_MAX || n > d->len - d->pos) return -1;
            zend_array *arr = zend_new_array((uint32_t)n);
            zend_hash_real_init_packed(arr);
            for (uint64_t i = 0; i < n; i++) {
                if (decode_value_hot(d, &arr->arPacked[i]) < 0) {
                    arr->nNumUsed = (uint32_t)i;
                    zend_array_destroy(arr);
                    return -1;
                }
            }
            dec_finish_packed(arr, n, out);
            return 0;
        }
        case TAG_PACKED_DELTA: {
            uint64_t n;
            if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
            /* v0 plus n-1 deltas is at least n wire bytes, the same linear
             * bound as PACKED_LONGS. */
            if (n > UINT32_MAX || n > d->len - d->pos) return -1;
            zend_array *arr = zend_new_array((uint32_t)n);
            zend_hash_real_init_packed(arr);
            /* First varint is v0, the rest are deltas; acc starts at zero so
             * one wrapping add covers both. Long cells own nothing, so the
             * failure destroy needs no nNumUsed bookkeeping. */
            uint64_t acc = 0;
            for (uint64_t i = 0; i < n; i++) {
                int64_t dv;
                if (varint_read_i64(d->buf, d->len, &d->pos, &dv) < 0) {
                    zend_array_destroy(arr);
                    return -1;
                }
                acc += (uint64_t)dv;
                if (UNEXPECTED(!dec_i64_fits_zend_long((int64_t)acc))) {
                    zend_array_destroy(arr);
                    return -1;
                }
                ZVAL_LONG(&arr->arPacked[i], (zend_long)(int64_t)acc);
            }
            dec_finish_packed(arr, n, out);
            return 0;
        }
        case TAG_PACKED_AFFINE: {
            uint64_t n;
            if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
            /* Sub-linear tag: wire bytes do not bound n here, the shared
             * budget does (see PHPSER_SUBLINEAR_MAX_ELEMS). */
            if (n > PHPSER_SUBLINEAR_MAX_ELEMS
                || d->sublinear_elems > PHPSER_SUBLINEAR_MAX_ELEMS - (uint32_t)n) {
                return -1;
            }
            d->sublinear_elems += (uint32_t)n;
            int64_t base_v, step;
            if (varint_read_i64(d->buf, d->len, &d->pos, &base_v) < 0) return -1;
            if (varint_read_i64(d->buf, d->len, &d->pos, &step) < 0) return -1;
            zend_array *arr = zend_new_array((uint32_t)n);
            zend_hash_real_init_packed(arr);
            uint64_t acc = (uint64_t)base_v;
            for (uint64_t i = 0; i < n; i++, acc += (uint64_t)step) {
                if (UNEXPECTED(!dec_i64_fits_zend_long((int64_t)acc))) {
                    zend_array_destroy(arr);
                    return -1;
                }
                ZVAL_LONG(&arr->arPacked[i], (zend_long)(int64_t)acc);
            }
            dec_finish_packed(arr, n, out);
            return 0;
        }
        case TAG_OBJECT_LEGACY: {
            uint64_t class_idx, blen;
            if (varint_read_u64(d->buf, d->len, &d->pos, &class_idx) < 0) return -1;
            if (varint_read_u64(d->buf, d->len, &d->pos, &blen) < 0) return -1;
            if (blen > UINT32_MAX || blen > d->len - d->pos) return -1;

            zend_string *class_name = dec_get_class_name(d, class_idx);
            if (!class_name) return -1;

            /* allowed_classes filter: if this class is disallowed, build an
             * incomplete-class instance and skip the legacy serializer
             * payload. ce->unserialize would otherwise instantiate the real
             * class, which the option exists to prevent. */
            if (!dec_class_allowed(d, class_idx, class_name)) {
                d->pos += blen;
                if (dec_make_incomplete(out, class_name) < 0) return -1;
                dec_register(d, out);
                return 0;
            }
            zend_class_entry *ce = dec_class_resolve(d, class_idx, class_name);
            if (!ce || ce->unserialize == NULL) {
                /* Unknown class or no C-level unserializer: skip the payload,
                 * yield NULL, and register a NULL id slot so later TAG_REFs
                 * resolve to NULL. */
                d->pos += blen;
                ZVAL_NULL(out);
                dec_register(d, out);
                return 0;
            }
            /* ce->unserialize initializes *out. var_hash is NULL, as on the
             * encode side. */
            const unsigned char *payload = d->buf + d->pos;
            d->pos += blen;
            if (ce->unserialize(out, ce, payload, (size_t)blen, NULL) != SUCCESS) {
                if (Z_TYPE_P(out) != IS_UNDEF) zval_ptr_dtor(out);
                ZVAL_NULL(out);
                return -1;
            }
            dec_register(d, out);
            return 0;
        }
        case TAG_OBJECT_MAGIC: {
            uint64_t class_idx;
            if (varint_read_u64(d->buf, d->len, &d->pos, &class_idx) < 0) return -1;
            zend_string *class_name = dec_get_class_name(d, class_idx);
            if (!class_name) return -1;

            /* Denied classes still consume the data tree and preserve it as
             * properties, keeping encounter ids aligned. */
            int allowed = dec_class_allowed(d, class_idx, class_name);
            zend_class_entry *ce = allowed
                ? dec_class_resolve(d, class_idx, class_name) : NULL;
            /* Unknown class: decode to __PHP_Incomplete_Class with the original
             * name preserved, like a denied class, so a re-encode can recover
             * it. Uniform across OBJECT, MAGIC, and SLOTS. */
            int known = (ce != NULL);
            if (!ce) ce = PHP_IC_ENTRY;

            if (dec_ce_uninstantiable(ce)) {
                return -1;
            }

            if (object_init_ex(out, ce) != SUCCESS) {
                ZVAL_NULL(out);
                return -1;
            }
            if (!allowed || !known) php_store_class_name(out, class_name);
            /* Register the empty object before decoding the data array so a
             * back-ref inside it resolves here. __unserialize is deferred until
             * the whole graph is stitched. */
            dec_register(d, out);

            zval data;
            ZVAL_UNDEF(&data);
            if (decode_value(d, &data) < 0) {
                zval_ptr_dtor(&data);
                return -1;
            }
            if (Z_TYPE(data) != IS_ARRAY) {
                zval_ptr_dtor(&data);
                return -1;
            }
            if (allowed && ce->__unserialize != NULL) {
                dec_defer_unserialize(d, Z_OBJ_P(out), &data);
                /* Ownership of `data` transferred to deferred list. */
            } else {
                /* Without __unserialize, preserve decoded data as properties,
                 * including on incomplete classes. */
                if (dec_apply_data_as_props(Z_OBJ_P(out), Z_ARRVAL(data)) < 0) {
                    /* A typed slot rejected the data (pending TypeError). `out`
                     * is registered, so decode_destroy releases it. Do not
                     * queue __wakeup. */
                    zval_ptr_dtor(&data);
                    return -1;
                }
                zval_ptr_dtor(&data);
                /* Like native, a class with __serialize() + __wakeup() but no
                 * __unserialize() still gets __wakeup(). Gated on `allowed` so
                 * incomplete classes never run a hook. */
                if (allowed) {
                    dec_maybe_defer_wakeup(d, ce, Z_OBJ_P(out));
                }
            }
            return 0;
        }
        case TAG_ENUM: {
            uint64_t class_idx, case_idx;
            if (varint_read_u64(d->buf, d->len, &d->pos, &class_idx) < 0) return -1;
            if (varint_read_u64(d->buf, d->len, &d->pos, &case_idx) < 0) return -1;
            zend_string *cname = dec_get_class_name(d, class_idx);
            zend_string *casename = dec_get_zstr(d, case_idx);
            if (!cname || !casename) return -1;
            /* Unlike native unserialize's E: path, phpser applies allowed_classes
             * to enums too; see SECURITY.md. */
            if (!dec_class_allowed(d, class_idx, cname)) {
                if (dec_make_incomplete(out, cname) < 0) return -1;
                dec_register(d, out);
                return 0;
            }
            zend_class_entry *ce = dec_class_resolve(d, class_idx, cname);
            if (!ce || !(ce->ce_flags & ZEND_ACC_ENUM)) return -1;
            /* zend_enum_get_case assumes a valid case and dereferences
             * unconditionally. Validate first to avoid NULL dereferences or type
             * confusion in release builds. */
            zend_class_constant *cc = zend_hash_find_ptr(CE_CONSTANTS_TABLE(ce), casename);
            if (!cc || !(ZEND_CLASS_CONST_FLAGS(cc) & ZEND_CLASS_CONST_IS_CASE)) return -1;
            zend_object *obj = zend_enum_get_case(ce, casename);
            if (!obj) return -1;
            ZVAL_OBJ_COPY(out, obj);
            dec_register(d, out);
            return 0;
        }
        case TAG_OBJECT_SLOTS: {
            uint64_t class_idx, nprops;
            if (varint_read_u64(d->buf, d->len, &d->pos, &class_idx) < 0) return -1;
            if (varint_read_u64(d->buf, d->len, &d->pos, &nprops) < 0) return -1;
            if (nprops > UINT32_MAX || nprops > d->len - d->pos) return -1;
            zend_string *class_name = dec_get_class_name(d, class_idx);
            if (!class_name) return -1;

            int allowed = dec_class_allowed(d, class_idx, class_name);

            if (!allowed) {
                /* Denied classes must not autoload. Resident classes supply slot
                 * names; without a resident schema, consume values into a
                 * property-less incomplete object. */
                zend_class_entry *schema_ce =
                    dec_class_resolve_ex(d, class_idx, class_name, /* autoload */ 0);
                if (dec_make_incomplete(out, class_name) < 0) return -1;
                dec_register(d, out);
                zend_object *obj = Z_OBJ_P(out);
                if (schema_ce) {
                    uint32_t current_nprops = ce_table_slot_count(schema_ce);
                    if (nprops > current_nprops) goto slots_fail;
                    HashTable *obj_props = zend_std_get_properties(obj);
                    int pc = ce_table_slot_prefix_limit(
                        schema_ce, (uint32_t)nprops, current_nprops);
                    for (int pi = 0; pi < pc; pi++) {
                        zend_property_info *info = schema_ce->properties_info_table[pi];
                        if (info == NULL) continue;
                        zval tmp;
                        if (decode_value_hot(d, &tmp) < 0) goto slots_fail;
                        if (dec_install_prop(obj, obj_props, info->name, &tmp) < 0) {
                            goto slots_fail;
                        }
                    }
                } else {
                    /* Discarded values may have later back-references; id-table
                     * pins keep them alive until decode_destroy. */
                    for (uint64_t i = 0; i < nprops; i++) {
                        zval tmp;
                        if (decode_value_hot(d, &tmp) < 0) goto slots_fail;
                        zval_ptr_dtor(&tmp);
                    }
                }
                return 0;
            }

            /* Allowed: the layout must come from the real class. An unknown
             * class has none, so consume the values into a property-less
             * incomplete, as on the denied+unloaded path; the id still claims a
             * slot and later TAG_REFs resolve. */
            zend_class_entry *ce = dec_class_resolve(d, class_idx, class_name);
            if (!ce) {
                if (dec_make_incomplete(out, class_name) < 0) return -1;
                dec_register(d, out);
                for (uint64_t i = 0; i < nprops; i++) {
                    zval tmp;
                    if (decode_value_hot(d, &tmp) < 0) goto slots_fail;
                    zval_ptr_dtor(&tmp);
                }
                return 0;
            }
            uint32_t current_nprops = ce_table_slot_count(ce);
            if (nprops > current_nprops) return -1;
            if (dec_ce_uninstantiable(ce)) {
                return -1;
            }
            if (ce->serialize != NULL && ce->__unserialize == NULL) {
                return -1;
            }

            if (object_init_ex(out, ce) != SUCCESS) {
                ZVAL_NULL(out);
                return -1;
            }
            zend_object *obj = Z_OBJ_P(out);
            dec_register(d, out);
            int pc = ce_table_slot_prefix_limit(
                ce, (uint32_t)nprops, current_nprops);

            /* A class can gain __unserialize after encoding. Follow its current
             * definition: pass named slot values to the hook and suppress raw
             * writes and __wakeup. */
            if (ce->__unserialize != NULL) {
                zval data;
                array_init_size(&data, (uint32_t)nprops);
                zend_hash_real_init_mixed(Z_ARRVAL(data));
                for (int pi = 0; pi < pc; pi++) {
                    zend_property_info *info = ce->properties_info_table[pi];
                    if (info == NULL) continue;
                    zval tmp;
                    if (decode_value_hot(d, &tmp) < 0) {
                        zval_ptr_dtor(&data);
                        goto slots_fail;
                    }
                    zend_hash_update(Z_ARRVAL(data), info->name, &tmp);
                }
                dec_defer_unserialize(d, obj, &data);
                return 0;
            }

            for (int pi = 0; pi < pc; pi++) {
                zend_property_info *info = ce->properties_info_table[pi];
                if (info == NULL) continue;
                zval tmp;
                if (decode_value_hot(d, &tmp) < 0) goto slots_fail;
                if (dec_install_declared_slot(obj, info, &tmp) < 0) goto slots_fail;
            }
            dec_maybe_defer_wakeup(d, ce, obj);
            return 0;
        slots_fail:
            if (Z_TYPE_P(out) != IS_UNDEF) {
                zval_ptr_dtor(out);
                ZVAL_NULL(out);
            }
            return -1;
        }
        case TAG_OBJECT: {
            uint64_t class_idx, nprops;
            if (varint_read_u64(d->buf, d->len, &d->pos, &class_idx) < 0) return -1;
            if (varint_read_u64(d->buf, d->len, &d->pos, &nprops) < 0) return -1;
            /* Each prop is at least 2 bytes (key idx varint + value tag). */
            if (nprops > UINT32_MAX || nprops > (d->len - d->pos) / 2) return -1;
            zend_string *class_name = dec_get_class_name(d, class_idx);
            if (!class_name) return -1;

            /* Disallowed classes decode into __PHP_Incomplete_Class with the
             * original name in the magic property. */
            int allowed = dec_class_allowed(d, class_idx, class_name);
            zend_class_entry *ce = allowed
                ? dec_class_resolve(d, class_idx, class_name) : NULL;
            /* Unknown class: __PHP_Incomplete_Class with the original name
             * preserved, uniform with MAGIC/SLOTS. */
            int known = (ce != NULL);
            if (!ce) ce = PHP_IC_ENTRY;

            if (dec_ce_uninstantiable(ce)) {
                return -1;
            }

            /* TAG_OBJECT must not bypass a legacy Serializable class's
             * reconstruction hook. Such classes require TAG_OBJECT_LEGACY unless
             * they define __unserialize; incomplete placeholders have no legacy
             * hook. */
            if (allowed && ce->serialize != NULL && ce->__unserialize == NULL) {
                return -1;
            }

            if (object_init_ex(out, ce) != SUCCESS) {
                ZVAL_NULL(out);
                return -1;
            }
            if (!allowed || !known) php_store_class_name(out, class_name);
            zend_object *obj = Z_OBJ_P(out);
            /* Register before decoding properties so a back-ref inside a
             * property value (cycles, shared subobjects) can resolve to this
             * in-progress object. */
            dec_register(d, out);

            /* The current class definition controls reconstruction, regardless of wire
             * form: __unserialize receives the properties array and suppresses
             * __wakeup. Raw property writes would bypass its invariants. */
            if (allowed && ce->__unserialize != NULL) {
                zval data;
                array_init_size(&data, (uint32_t)nprops);
                zend_hash_real_init_mixed(Z_ARRVAL(data));
                for (uint64_t i = 0; i < nprops; i++) {
                    uint64_t key_idx;
                    if (varint_read_u64(d->buf, d->len, &d->pos, &key_idx) < 0) goto unser_fail;
                    zend_string *key = dec_get_zstr(d, key_idx);
                    if (!key) goto unser_fail;
                    zval tmp;
                    if (decode_value_hot(d, &tmp) < 0) goto unser_fail;
                    if (UNEXPECTED(dec_string_hash_chain_exhausted(
                            Z_ARRVAL(data), key))) {
                        zval_ptr_dtor(&tmp);
                        goto unser_fail;
                    }
                    zend_hash_update(Z_ARRVAL(data), key, &tmp);
                    continue;
                unser_fail:
                    zval_ptr_dtor(&data);
                    zval_ptr_dtor(out); ZVAL_NULL(out);
                    return -1;
                }
                dec_defer_unserialize(d, obj, &data);
                return 0;
            }

            /* Direct slot writes avoid materializing a properties HashTable for DTOs.
             * Preserve native typed-slot checks and visibility bypass; use the
             * materialized path for custom handlers or unresolved keys. */
            HashTable *obj_props = NULL;
            if (UNEXPECTED(obj->handlers->get_properties != zend_std_get_properties)) {
                obj_props = zend_std_get_properties(obj);
            }

            for (uint64_t i = 0; i < nprops; i++) {
                uint64_t key_idx;
                if (varint_read_u64(d->buf, d->len, &d->pos, &key_idx) < 0) goto obj_fail;
                zend_string *key = dec_get_zstr(d, key_idx);
                if (!key) goto obj_fail;
                /* Reject a forbidden dynamic property at the key, as native
                 * does; decoding the value first could autoload a class. */
                if (UNEXPECTED(dec_dynamic_prop_forbidden(obj, obj_props, key))) {
                    goto obj_fail;
                }
                zval tmp;
                if (decode_value_hot(d, &tmp) < 0) goto obj_fail;
                if (EXPECTED(obj_props == NULL)) {
                    zend_property_info *info = dec_prop_info_for_key(ce, key);
                    if (EXPECTED(info != NULL)) {
                        if (dec_install_declared_slot(obj, info, &tmp) < 0) {
                            goto obj_fail;
                        }
                        continue;
                    }
                    /* Non-slot key: materialize once and route this and every
                     * later key through dec_install_prop, which reaches
                     * declared slots via IS_INDIRECT entries. */
                    obj_props = zend_std_get_properties(obj);
                }
                /* dec_install_prop mirrors var_unserializer.re:608-708:
                 * IS_INDIRECT slots get typed-property verification, dynamic
                 * props go through zend_hash_update with overwrite semantics.
                 * On typed-mismatch tmp is dtor'd inside the helper. */
                if (dec_install_prop(obj, obj_props, key, &tmp) < 0) goto obj_fail;
                continue;
            obj_fail:
                zval_ptr_dtor(out); ZVAL_NULL(out); return -1;
            }
            /* Defer __wakeup until the full graph, including cycle back-edges,
             * is stitched. */
            dec_maybe_defer_wakeup(d, ce, obj);
            return 0;
        }
        case TAG_ASSOC_DICT: {
            uint64_t n;
            if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
            /* Wire layout: n key indices, then n values (encoder writes keys
             * in one pass and values in a second). Each entry needs at least
             * one byte of key idx and one byte of value tag. */
            if (n > UINT32_MAX || n > (d->len - d->pos) / 2) return -1;
            /* Schema validation rejects duplicate and numeric keys before insertion. */
            int use_add_new;
            zend_string **keys = dec_read_schema_keys(d, n, &use_add_new);
            if (!keys) return -1;
            zend_array *arr = zend_new_array((uint32_t)n);
            for (uint64_t i = 0; i < n; i++) {
                zval tmp;
                if (decode_value_hot(d, &tmp) < 0) goto assoc_dict_fail;
                if (use_add_new) {
                    zend_hash_add_new(arr, keys[i], &tmp);
                } else {
                    zend_symtable_update(arr, keys[i], &tmp);
                }
            }
            efree(keys);
            ZVAL_ARR(out, arr);
            return 0;
        assoc_dict_fail:
            efree(keys);
            zend_array_destroy(arr);
            return -1;
        }
        case TAG_ASSOC: {
            uint64_t n;
            if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
            /* Each assoc entry is at least 2 bytes (key tag + value tag). */
            if (n > UINT32_MAX || n > (d->len - d->pos) / 2) return -1;
            zend_array *arr = zend_new_array((uint32_t)n);
            zend_hash_real_init_mixed(arr);
            for (uint64_t i = 0; i < n; i++) {
                key_val k = {0};
                if (decode_key(d, &k) < 0) goto assoc_fail;
                zval tmp;
                if (decode_value_hot(d, &tmp) < 0) {
                    if (k.kind == KV_OWNED_STR) zend_string_release(k.str);
                    goto assoc_fail;
                }
                /* TAG_ASSOC keeps update semantics for duplicate collapse and
                 * canonical numeric-string coercion on signed and unsigned
                 * frames; HMAC verification does not prove key uniqueness. */
                if (UNEXPECTED(dec_assoc_update_bounded(arr, &k, &tmp) < 0)) {
                    zval_ptr_dtor(&tmp);
                    if (k.kind == KV_OWNED_STR) zend_string_release(k.str);
                    goto assoc_fail;
                }
                if (k.kind == KV_OWNED_STR) zend_string_release(k.str);
                continue;
            assoc_fail:
                zend_array_destroy(arr);
                return -1;
            }
            ZVAL_ARR(out, arr);
            return 0;
        }
        case TAG_TABLE:
            return dec_decode_table(d, out);
        case TAG_ROWSET:
            return dec_decode_rowset(d, out);
        default:
            return -1;
    }
}

static zend_never_inline int dec_decode_table(decode_ctx *d, zval *out) {
    uint64_t nrows, ncols;
    if (varint_read_u64(d->buf, d->len, &d->pos, &nrows) < 0) return -1;
    if (varint_read_u64(d->buf, d->len, &d->pos, &ncols) < 0) return -1;
    if (ncols > UINT32_MAX || nrows > UINT32_MAX || ncols == 0) return -1;
    if (ncols > (d->len - d->pos) / 2) return -1;
    /* Each of the nrows*ncols cells is at least one byte on the wire. */
    if (nrows > (d->len - d->pos) / ncols) return -1;

    int use_add_new;
    zend_string **keys = dec_read_schema_keys(d, ncols, &use_add_new);
    if (!keys) return -1;

    /* Scatter one decoded column at a time to bound temporary memory to one
     * column. Table inserts take ownership of the cells. */
    /* Rows are materialized only after the FIRST column decodes. A malformed
     * frame that fails on column 0 must not pay nrows row-HT allocations: the
     * nrows bound is one wire byte per cell, so eager row allocation turns a
     * rejected payload into a ~70x memory amplification (OOM-fatal, which is
     * uncatchable and stricter than the decoder's return-NULL contract). */
    zend_array *outer = NULL;

    /* Rows share unique non-numeric keys and table geometry, so build the hash
     * layout once. Initialize values to IS_UNDEF for safe partial cleanup;
     * ZVAL_COPY_VALUE preserves each bucket's u2 collision-chain link. */
    uint32_t *tpl_hash = NULL;   /* hash-slot image, tpl_nslots entries */
    zend_ulong *tpl_h = NULL;    /* per-column precomputed key hash */
    uint32_t *tpl_next = NULL;   /* per-column collision chain link */
    uint32_t tpl_nslots = 0;

    zval *colbuf = (zval *)safe_emalloc((size_t)nrows, sizeof(zval), 0);
    for (uint64_t c = 0; c < ncols; c++) {
        if (d->pos >= d->len) goto table_fail;
        uint8_t col_tag = d->buf[d->pos++];
        if (dec_table_column(d, colbuf, nrows, col_tag) < 0) {
            /* dec_table_column blanks the unfilled tail to IS_UNDEF, so this
             * uniform dtor releases the decoded prefix and no-ops the rest.
             * (Reached only here; the goto below must NOT re-dtor colbuf.) */
            for (uint64_t r = 0; r < nrows; r++) zval_ptr_dtor(&colbuf[r]);
            goto table_fail;
        }
        if (outer == NULL) {
            outer = zend_new_array((uint32_t)nrows);
            zend_hash_real_init_packed(outer);
            if (use_add_new) {
                int tpl_static_keys = 1;
                for (uint64_t r = 0; r < nrows; r++) {
                    zend_array *row = zend_new_array((uint32_t)ncols);
                    zend_hash_real_init_mixed(row);
                    if (tpl_hash == NULL) {
                        /* First row fixes the geometry; nTableMask is the
                         * negated table size. */
                        tpl_nslots = (uint32_t)-(int32_t)row->nTableMask;
                        tpl_hash = (uint32_t *)safe_emalloc(
                            tpl_nslots, sizeof(uint32_t), 0);
                        memset(tpl_hash, 0xFF,
                               (size_t)tpl_nslots * sizeof(uint32_t));
                        tpl_h = (zend_ulong *)safe_emalloc(
                            (size_t)ncols, sizeof(zend_ulong), 0);
                        tpl_next = (uint32_t *)safe_emalloc(
                            (size_t)ncols, sizeof(uint32_t), 0);
                        for (uint32_t k = 0; k < (uint32_t)ncols; k++) {
                            zend_ulong h = zend_string_hash_val(keys[k]);
                            uint32_t slot = (uint32_t)h & (tpl_nslots - 1);
                            tpl_h[k] = h;
                            tpl_next[k] = tpl_hash[slot];
                            tpl_hash[slot] = HT_IDX_TO_HASH(k);
                            if (!ZSTR_IS_INTERNED(keys[k])) {
                                tpl_static_keys = 0;
                            }
                        }
                    }
                    memcpy((uint32_t *)row->arData - tpl_nslots, tpl_hash,
                           (size_t)tpl_nslots * sizeof(uint32_t));
                    Bucket *b = row->arData;
                    for (uint32_t k = 0; k < (uint32_t)ncols; k++, b++) {
                        b->h = tpl_h[k];
                        b->key = keys[k];
                        ZVAL_UNDEF(&b->val);
                        Z_NEXT(b->val) = tpl_next[k];
                    }
                    row->nNumUsed = (uint32_t)ncols;
                    row->nNumOfElements = (uint32_t)ncols;
                    if (!tpl_static_keys) {
                        HT_FLAGS(row) &= ~HASH_FLAG_STATIC_KEYS;
                    }
                    ZVAL_ARR(&outer->arPacked[r], row);
                }
                /* Every row's buckets borrowed the dict keys above; take the
                 * nrows references in one bump per non-interned key (interned
                 * strings carry no refcount). Balanced by the per-bucket
                 * release each row's destroy performs. */
                for (uint32_t k = 0; k < (uint32_t)ncols; k++) {
                    if (!ZSTR_IS_INTERNED(keys[k])) {
                        GC_SET_REFCOUNT(keys[k],
                            GC_REFCOUNT(keys[k]) + (uint32_t)nrows);
                    }
                }
            } else {
                for (uint64_t r = 0; r < nrows; r++) {
                    ZVAL_ARR(&outer->arPacked[r],
                             zend_new_array((uint32_t)ncols));
                }
            }
            /* Well-formed now so a later failure destroys rows + scattered
             * cells with one zend_array_destroy. */
            outer->nNumUsed = (uint32_t)nrows;
            outer->nNumOfElements = (uint32_t)nrows;
            outer->nNextFreeElement = (zend_long)nrows;
        }
        if (use_add_new) {
            for (uint64_t r = 0; r < nrows; r++) {
                Bucket *b = Z_ARR(outer->arPacked[r])->arData + (uint32_t)c;
                ZVAL_COPY_VALUE(&b->val, &colbuf[r]);
            }
        } else {
            zend_string *zs = keys[c];
            for (uint64_t r = 0; r < nrows; r++) {
                zend_symtable_update(Z_ARR(outer->arPacked[r]), zs, &colbuf[r]);
            }
        }
        /* colbuf[r] were moved into the rows; the next dec_table_column call
         * overwrites the buffer, so leaving the stale copies is fine. */
    }

    efree(colbuf);
    efree(keys);
    if (tpl_hash) { efree(tpl_hash); efree(tpl_h); efree(tpl_next); }
    /* ncols >= 1 is checked above and the loop's only early exit is the failure
     * goto, so column 0 always materialized `outer` by the time we get here. */
    ZVAL_ARR(out, outer);
    return 0;

table_fail:
    /* outer owns every row and every cell already scattered into them; a full
     * destroy releases the lot. The in-flight column (if any) was dtored above
     * before the jump, so colbuf is only freed here, never re-dtored. */
    efree(colbuf);
    efree(keys);
    if (tpl_hash) { efree(tpl_hash); efree(tpl_h); efree(tpl_next); }
    if (outer) zend_array_destroy(outer);
    return -1;
}

static zend_never_inline int dec_decode_rowset(decode_ctx *d, zval *out) {
    uint64_t nrows, ncols;
    if (varint_read_u64(d->buf, d->len, &d->pos, &nrows) < 0) return -1;
    if (varint_read_u64(d->buf, d->len, &d->pos, &ncols) < 0) return -1;
    if (ncols > UINT32_MAX || nrows > UINT32_MAX || ncols == 0) return -1;
    if (ncols > (d->len - d->pos) / 2
        || nrows > (d->len - d->pos) / ncols) {
        return -1;
    }

    int use_add_new;
    zend_string **keys = dec_read_schema_keys(d, ncols, &use_add_new);
    if (!keys) return -1;

    zend_array *outer = zend_new_array((uint32_t)nrows);
    zend_hash_real_init_packed(outer);
    uint64_t r;
    for (r = 0; r < nrows; r++) {
        zend_array *row = zend_new_array((uint32_t)ncols);
        for (uint64_t c = 0; c < ncols; c++) {
            zend_string *zs = keys[c];
            zval tmp;
            if (decode_value_hot(d, &tmp) < 0) {
                zend_array_destroy(row);
                goto rowset_fail;
            }
            if (use_add_new) {
                zend_hash_add_new(row, zs, &tmp);
            } else {
                zend_symtable_update(row, zs, &tmp);
            }
        }
        ZVAL_ARR(&outer->arPacked[r], row);
    }

    efree(keys);
    outer->nNumUsed = (uint32_t)nrows;
    outer->nNumOfElements = (uint32_t)nrows;
    outer->nNextFreeElement = (zend_long)nrows;
    ZVAL_ARR(out, outer);
    return 0;

rowset_fail:
    efree(keys);
    for (uint64_t i = 0; i < r; i++) {
        zend_array_destroy(Z_ARR(outer->arPacked[i]));
    }
    zend_array_destroy(outer);
    return -1;
}

/* Below this key count the O(n^2) pairwise scan beats building a HashTable
 * (no alloc, cache-resident); above it, switch to the hashed set. Columnar
 * schemas are almost always a handful of columns, so the scan path dominates. */
#define SCHEMA_UNIQ_SCAN_MAX 32
static int dec_schema_keys_are_unique(zend_string **keys, uint64_t nkeys) {
    if (nkeys <= 1) {
        return 1;
    }

    if (nkeys <= SCHEMA_UNIQ_SCAN_MAX) {
        for (uint64_t i = 1; i < nkeys; i++) {
            for (uint64_t j = 0; j < i; j++) {
                if (zend_string_equals(keys[i], keys[j])) {
                    return 0;
                }
            }
        }
        return 1;
    }

    HashTable seen;
    zend_hash_init(&seen, (uint32_t)nkeys, NULL, NULL, 0);
    for (uint64_t i = 0; i < nkeys; i++) {
        if (UNEXPECTED(dec_string_hash_chain_exhausted(&seen, keys[i]))) {
            zend_hash_destroy(&seen);
            return -1;
        }
        if (zend_hash_add_empty_element(&seen, keys[i]) == NULL) {
            zend_hash_destroy(&seen);
            return 0;
        }
    }
    zend_hash_destroy(&seen);
    return 1;
}

static zend_string **dec_read_schema_keys(decode_ctx *d, uint64_t nkeys, int *use_add_new) {
    zend_string **keys = (zend_string **)safe_emalloc((size_t)nkeys, sizeof(zend_string *), 0);
    int has_numeric = 0;
    for (uint64_t i = 0; i < nkeys; i++) {
        uint64_t idx;
        if (varint_read_u64(d->buf, d->len, &d->pos, &idx) < 0) {
            efree(keys);
            return NULL;
        }
        keys[i] = dec_get_zstr(d, idx);
        if (!keys[i]) {
            efree(keys);
            return NULL;
        }
        zend_ulong h;
        if (ZEND_HANDLE_NUMERIC(keys[i], h)) has_numeric = 1;
    }
    /* Schema keys must be distinct non-numeric strings, including signed frames.
     * Duplicate keys would create phantom buckets; numeric-key update fallback
     * would expose unbudgeted integer hash chains and quadratic decoding.
     * The encoder requires string-key buckets, so valid arrays cannot emit
     * either rejected shape. HMAC verification does not establish uniqueness. */
    int unique = dec_schema_keys_are_unique(keys, nkeys);
    if (UNEXPECTED(unique <= 0) || UNEXPECTED(has_numeric)) {
        efree(keys);
        return NULL;
    }
    *use_add_new = 1;
    return keys;
}

/* Shared cleanup for depth/size rejects: free body, destroy ctx (which can
 * run destructors), reclassify as EXCEPTION if cleanup threw, optionally
 * raise the userland exception. */
static zend_string *enc_finish_overflow(
    smart_str *body, encode_ctx *ctx, bool throw_on_overflow,
    phpser_enc_status *status, phpser_enc_status kind)
{
    if (status) *status = kind;
    smart_str_free(body);
    bool cleanup_can_throw = ctx->pins_active;
    enc_ctx_destroy(ctx);
    if (UNEXPECTED(cleanup_can_throw && EG(exception))) {
        if (status) *status = PHPSER_ENC_EXCEPTION;
        return NULL;
    }
    if (throw_on_overflow) {
        if (kind == PHPSER_ENC_DEPTH) {
            zend_throw_exception_ex(zend_ce_exception, 0,
                "phpser: maximum nesting depth (%d) exceeded", MAX_DEPTH);
        } else {
            zend_throw_exception_ex(zend_ce_exception, 0,
                "phpser: string length exceeds the 4 GiB wire-format limit");
        }
    }
    return NULL;
}

zend_string *phpser_encode_zval_ex(zval *value, bool throw_on_overflow,
                                          phpser_enc_status *status) {
    encode_ctx ctx;
    enc_ctx_init(&ctx);
    if (status) *status = PHPSER_ENC_OK;

    smart_str body = {0};
    /* Estimate 16 bytes per top-level array element, capped at 256 KB to bound
     * speculative allocation; smaller inputs start at 256 bytes. */
    size_t body_estimate = 256;
    if (Z_TYPE_P(value) == IS_ARRAY) {
        uint32_t n = zend_hash_num_elements(Z_ARRVAL_P(value));
        if (n > 16) {
            size_t est = (size_t)n * 16;
            body_estimate = est > (256 * 1024) ? (256 * 1024) : est;
        }
        /* Allow two distinct strings per element at <=50% load. Cap initial
         * zeroing; larger caches grow geometrically. */
        if (n > 64) {
            uint32_t want = n < 8192 ? n * 4 : 32768;
            uint32_t cap = 128;
            while (cap < want) cap <<= 1;
            ctx.icache_init_cap = cap;
        }
    } else if (Z_TYPE_P(value) == IS_OBJECT) {
        /* Same seeding for a top-level object: each property and class name
         * takes a slot. Count declared slots without materializing, plus any
         * dynamic table; an over-estimate only costs zeroed bytes. */
        zend_class_entry *sce = Z_OBJCE_P(value);
        uint32_t n = sce->default_properties_count;
        if (Z_OBJ_P(value)->properties) {
            n += zend_hash_num_elements(Z_OBJ_P(value)->properties);
        }
        if (n > 64) {
            uint32_t want = n < 8192 ? n * 4 : 32768;
            uint32_t cap = 128;
            while (cap < want) cap <<= 1;
            ctx.icache_init_cap = cap;
        }
    }
    smart_str_alloc(&body, body_estimate, 0);
    encode_value(&body, &ctx, value);

    /* A userland __serialize/__sleep/Serializable::serialize threw mid-walk.
     * Never hand back the truncated frame (it has a TAG_NULL hole where the
     * throwing object was); the exception stays pending for the caller. Check
     * before the overflow branches so we never raise a competing depth/size
     * exception on top of the userland one. */
    if (UNEXPECTED(ctx.failed
        || (ctx.pins_active && EG(exception)))) {
        smart_str_free(&body);
        enc_ctx_destroy(&ctx);
        if (status) *status = PHPSER_ENC_EXCEPTION;
        return NULL;
    }
    /* Reject over-deep input: the decoder caps at the same MAX_DEPTH, so a
     * truncated payload would decode to NULL in full. */
    if (UNEXPECTED(ctx.depth_exceeded)) {
        return enc_finish_overflow(
            &body, &ctx, throw_on_overflow, status, PHPSER_ENC_DEPTH);
    }
    /* Likewise for an over-4GiB string/blob, which the decoder's per-string
     * UINT32_MAX cap would reject. */
    if (UNEXPECTED(ctx.size_exceeded)) {
        return enc_finish_overflow(
            &body, &ctx, throw_on_overflow, status, PHPSER_ENC_SIZE);
    }
    size_t body_len = body.s ? ZSTR_LEN(body.s) : 0;

    /* Frame: [version][varint ndict][per-entry varint(len)+bytes][body]. Pre-size
     * for the header and body; smart_str still checks each append. */
    size_t header_max = 1 + VARINT_MAX_BYTES_U32;
    for (uint32_t i = 0; i < ctx.dict_len; i++) {
        header_max += VARINT_MAX_BYTES_U32 + ZSTR_LEN(ctx.dict[i]);
    }

    smart_str out = {0};
    /* +1 for the smart_str_0 NUL terminator at the end. */
    smart_str_alloc(&out, header_max + body_len + 1, 0);

    smart_str_appendc(&out, ctx.wire_v2 ? PHPSER_VERSION_V2 : PHPSER_VERSION);
    varint_write_u64(&out, ctx.dict_len);
    for (uint32_t i = 0; i < ctx.dict_len; i++) {
        zend_string *zs = ctx.dict[i];
        varint_write_u64(&out, ZSTR_LEN(zs));
        smart_str_appendl(&out, ZSTR_VAL(zs), ZSTR_LEN(zs));
    }
    if (body.s) {
        smart_str_appendl(&out, ZSTR_VAL(body.s), body_len);
        smart_str_free(&body);
    }
    smart_str_0(&out);

    bool cleanup_can_throw = ctx.pins_active;
    enc_ctx_destroy(&ctx);
    if (UNEXPECTED(cleanup_can_throw && EG(exception))) {
        zend_string_release(out.s);
        if (status) *status = PHPSER_ENC_EXCEPTION;
        return NULL;
    }
    return out.s;
}

zend_string *phpser_encode_zval(zval *value, bool throw_on_overflow) {
    return phpser_encode_zval_ex(value, throw_on_overflow, NULL);
}

int phpser_decode_buf_opts(
    const char *str, size_t str_len, zval *out,
    int allowed_mode, HashTable *allowed_set, bool require_exact)
{
    decode_ctx d = {0};
    d.buf = (const uint8_t *)str;
    d.len = str_len;
    d.allowed_mode = allowed_mode;
    d.allowed_set = allowed_set;

    if (decode_header(&d) < 0) {
        decode_destroy(&d);
        ZVAL_NULL(out);
        return -1;
    }
    if (decode_value(&d, out) < 0) {
        zval_ptr_dtor(out);
        ZVAL_NULL(out);
        decode_destroy(&d);
        return -1;
    }
    /* Signed and session frames require exact consumption before hooks run. The
     * unsigned entry point retains historical trailing-byte tolerance. */
    if (require_exact && UNEXPECTED(d.pos != d.len)) {
        zval_ptr_dtor(out);
        ZVAL_NULL(out);
        decode_destroy(&d);
        return -1;
    }
    /* The graph is fully stitched before any hook runs. Dispatch wakeup and
     * unserialize events in their shared encounter order, matching native. */
    for (uint32_t i = 0; i < d.deferred_len; i++) {
        zend_object *obj = d.deferred[i].obj;
        zval retval;
        ZVAL_UNDEF(&retval);
        if (d.deferred[i].kind == DEFER_UNSERIALIZE) {
            zend_call_known_instance_method_with_1_params(
                obj->ce->__unserialize, obj, &retval,
                &d.deferred[i].data);
        } else {
            zval *wake_fn_zv = zend_hash_find_known_hash(
                &obj->ce->function_table, ZSTR_KNOWN(ZEND_STR_WAKEUP));
            if (!wake_fn_zv) continue;
            zend_call_known_instance_method_with_0_params(
                Z_FUNC_P(wake_fn_zv), obj, &retval);
        }
        zval_ptr_dtor(&retval);
        if (UNEXPECTED(EG(exception))) goto done;
    }
done:
    decode_destroy(&d);
    /* The PHP entry point is not declared by-reference, so unwrap a top-level
     * TAG_NEW_REF before returning to Zend. */
    if (Z_TYPE_P(out) == IS_REFERENCE) {
        zend_reference *ref = Z_REF_P(out);
        zval inner;
        ZVAL_COPY(&inner, &ref->val);
        zval_ptr_dtor(out);
        ZVAL_COPY_VALUE(out, &inner);
    }
    /* C callers, especially the session handler, need failure even when Zend
     * will propagate a pending hook exception. Destroy the graph and set out
     * to NULL to preserve the error contract and avoid leaking it. */
    if (UNEXPECTED(EG(exception))) {
        zval_ptr_dtor(out);
        ZVAL_NULL(out);
        return -1;
    }
    return 0;
}
