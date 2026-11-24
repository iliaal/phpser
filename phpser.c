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
#include "Zend/zend_hash.h"
#if defined(_MSC_VER) && defined(_M_X64)
# include <intrin.h>
#endif

#include "Zend/zend_enum.h"
#include "Zend/zend_exceptions.h"
#include "Zend/zend_execute.h"          /* zend_verify_prop_assignable_by_ref */
#include "Zend/zend_objects_API.h"      /* zend_get_typed_property_info_for_slot */
#include "ext/standard/basic_functions.h"
#include "ext/standard/php_incomplete_class.h"
#include "ext/standard/php_var.h"
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

/* PHP 8.2 lacks ZEND_STATIC_ASSERT (added in 8.3). */
#ifndef ZEND_STATIC_ASSERT
# if defined(__STDC_VERSION__) && __STDC_VERSION__ >= 201112L
#  define ZEND_STATIC_ASSERT(c, msg) _Static_assert(c, msg)
# elif defined(_MSC_VER)
#  define ZEND_STATIC_ASSERT(c, msg) static_assert(c, msg)
# else
#  define ZEND_STATIC_ASSERT(c, msg)
# endif
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
 * container tags (0x12-0x18: TAG_OBJECT_SLOTS, TAG_ASSOC_DICT, TAG_ROWSET,
 * TAG_TABLE, TAG_PACKED_DELTA, TAG_PACKED_AFFINE, TAG_SHARED_ARRAY); decoders
 * accept both bytes. */
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
                                      TAG_NEW_REF, and TAG_SHARED_ARRAY each implicitly claim the
                                      next id (TAG_SHARED_ARRAY after its contents, the rest
                                      before). */
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
#define TAG_SHARED_ARRAY    0x18   /* array value follows (wire v2); claims the next id once the
                                      array is complete, after every id claimed inside it, so
                                      later TAG_REFs share the decoded zend_array by refcount.
                                      The inner value must be an array container tag. The encoder
                                      emits it only for pointer-shared, reference-free nested
                                      arrays; the top level never claims. */

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

/* Stack-backed byte buffer for the encoded body. Payloads <= 1 KiB avoid
 * heap allocation for the body entirely; larger ones grow geometrically up to
 * 2 MiB and 1.5x thereafter (or 4 KiB-aligned exact size for large appends). */
#define ENC_BUF_INLINE_CAP 1024u

typedef struct {
    char *data;
    size_t len;
    size_t cap;
    char stack[ENC_BUF_INLINE_CAP];
} enc_buf;

static zend_always_inline void enc_buf_init(enc_buf *b) {
    b->data = b->stack;
    b->len = 0;
    b->cap = ENC_BUF_INLINE_CAP;
}

static zend_always_inline void enc_buf_free(enc_buf *b) {
    if (b->data != b->stack) {
        efree(b->data);
        b->data = b->stack;
        b->cap = ENC_BUF_INLINE_CAP;
    }
    b->len = 0;
}

static zend_never_inline void enc_buf_grow(enc_buf *b, size_t need) {
    if (UNEXPECTED(need > SIZE_MAX - b->len)) {
        zend_error_noreturn(E_ERROR, "Possible integer overflow in memory allocation");
    }
    size_t req = b->len + need;
    size_t new_cap;
    if (b->data == b->stack) {
        new_cap = 4096;
        while (new_cap < req && new_cap < ((size_t)2 << 20)) {
            new_cap <<= 1;
        }
        if (new_cap < req) {
            new_cap = req <= SIZE_MAX - 4095 ? ((req + 4095) & ~(size_t)4095) : req;
        }
        char *heap = emalloc(new_cap);
        if (b->len) memcpy(heap, b->stack, b->len);
        b->data = heap;
        b->cap = new_cap;
        return;
    }
    new_cap = b->cap;
    if (new_cap < ((size_t)2 << 20)) {
        do {
            new_cap <<= 1;
        } while (new_cap < req && new_cap < ((size_t)2 << 20));
    } else if (new_cap <= SIZE_MAX - (new_cap >> 1)) {
        new_cap += new_cap >> 1;
    }
    if (new_cap < req) {
        new_cap = req <= SIZE_MAX - 4095 ? ((req + 4095) & ~(size_t)4095) : req;
    }
    b->data = erealloc(b->data, new_cap);
    b->cap = new_cap;
}

static zend_always_inline void enc_buf_reserve(enc_buf *b, size_t need) {
    if (UNEXPECTED(need > b->cap - b->len)) {
        enc_buf_grow(b, need);
    }
}

static zend_always_inline void enc_buf_appendc(enc_buf *b, uint8_t c) {
    enc_buf_reserve(b, 1);
    b->data[b->len++] = (char)c;
}

static zend_always_inline void enc_buf_appendl(enc_buf *b, const char *src, size_t n) {
    enc_buf_reserve(b, n);
    if (n) {
        memcpy(b->data + b->len, src, n);
        b->len += n;
    }
}

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

static zend_always_inline void varint_write_u64(enc_buf *b, uint64_t v) {
    enc_buf_reserve(b, VARINT_MAX_BYTES);
    b->len = varint_put(b->data, b->len, v);
}

static zend_always_inline void emit_tag_and_varint(enc_buf *b, uint8_t tag, uint64_t v) {
    enc_buf_reserve(b, 1 + VARINT_MAX_BYTES);
    char *base = b->data;
    size_t pos = b->len;
    base[pos++] = (char)tag;
    b->len = varint_put(base, pos, v);
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

static zend_always_inline uint64_t zigzag_encode64(int64_t v) {
    return ((uint64_t)v << 1) ^ ((uint64_t)(v >> 63));
}

/* Exact LEB128 byte length: floor(bits/7) + 1 = ((bits * 37) >> 8) + 1 for
 * all 0 <= bits <= 63. */
static zend_always_inline uint32_t varint_len_u64(uint64_t v) {
#if defined(__GNUC__) || defined(__clang__)
    uint32_t bits = 63u - (uint32_t)__builtin_clzll(v | 1ULL);
    return ((bits * 37u) >> 8) + 1u;
#elif defined(_MSC_VER) && defined(_M_X64)
    unsigned long bits;
    _BitScanReverse64(&bits, v | 1ULL);
    return (((uint32_t)bits * 37u) >> 8) + 1u;
#else
    uint32_t n = 1;
    while (v >= 0x80) { v >>= 7; n++; }
    return n;
#endif
}

static inline int64_t zigzag_decode64(uint64_t v) {
    return (int64_t)((v >> 1) ^ (~(v & 1) + 1));
}

static zend_always_inline void varint_write_i64(enc_buf *b, int64_t v) {
    varint_write_u64(b, zigzag_encode64(v));
}

static inline int varint_read_i64(const uint8_t *buf, size_t buflen, size_t *pos, int64_t *out) {
    uint64_t u;
    if (varint_read_u64(buf, buflen, pos, &u) < 0) return -1;
    *out = zigzag_decode64(u);
    return 0;
}

/* Unrolled 1/2/3-byte varint decoder for tight numeric run loops. Distinct
 * branches for byte 1 and byte 2 prevent TAGE branch-predictor aliasing on
 * multi-byte integer streams (e.g. random 21-bit deltas) and check bounds
 * once per 3-byte window. */
static zend_always_inline int varint_read_u64_fast(
    const uint8_t *buf, size_t buflen, size_t *pos, uint64_t *out)
{
    size_t p = *pos;
    if (UNEXPECTED(p >= buflen)) return -1;
    uint8_t b0 = buf[p];
    if ((b0 & 0x80) == 0) {
        *out = b0;
        *pos = p + 1;
        return 0;
    }
    if (EXPECTED(buflen - p >= 3)) {
        uint8_t b1 = buf[p + 1];
        uint64_t v = (uint64_t)(b0 & 0x7f) | ((uint64_t)(b1 & 0x7f) << 7);
        if ((b1 & 0x80) == 0) {
            *out = v;
            *pos = p + 2;
            return 0;
        }
        uint8_t b2 = buf[p + 2];
        v |= (uint64_t)(b2 & 0x7f) << 14;
        if ((b2 & 0x80) == 0) {
            *out = v;
            *pos = p + 3;
            return 0;
        }
        p += 3;
        int shift = 21;
        while (p < buflen) {
            uint8_t b = buf[p++];
            if (shift >= 64) return -1;
            uint64_t chunk = b & 0x7f;
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
    return varint_read_u64(buf, buflen, pos, out);
}

static zend_always_inline int varint_read_i64_fast(
    const uint8_t *buf, size_t buflen, size_t *pos, int64_t *out)
{
    uint64_t u;
    if (UNEXPECTED(varint_read_u64_fast(buf, buflen, pos, &u) < 0)) return -1;
    *out = zigzag_decode64(u);
    return 0;
}

/* Wire doubles are 8-byte little-endian IEEE754, independent of host order. */
static zend_always_inline void enc_buf_append_tag_le64(enc_buf *b, uint8_t tag, double v) {
    enc_buf_reserve(b, 9);
    char *base = b->data;
    size_t pos = b->len;
    base[pos++] = (char)tag;
    b->len = le64_put(base, pos, v);
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
#define ENC_DICT_MAX_CHAIN_LENGTH 64

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
#define PHPSER_MATERIALIZE_ALLOWANCE (16u * 1024u * 1024u)
#define PHPSER_MATERIALIZE_RATIO 64u

static uint64_t phpser_mixed_array_bytes(uint32_t count) {
    uint32_t capacity = HT_MIN_SIZE;
    if (count > HT_MAX_SIZE) return UINT64_MAX;
    while (capacity < count) capacity *= 2;
    /* Use 64-bit allocation sizes on both widths so encoder fallback decisions
     * also fit a decoder with larger buckets. */
    return 56 + (uint64_t)capacity * 40;
}

/* Tracked entities are pinned before user code runs, preventing address reuse from
 * masquerading as a back-reference. Unique plain objects still claim ids without
 * table entries or delayed destruction. */
enum { ENC_ID_OBJECT, ENC_ID_REFERENCE, ENC_ID_ARRAY, ENC_ID_ARRAY_IMMUTABLE };

typedef struct {
    uintptr_t ptr;   /* 0 = empty */
    uint32_t  id;
    uint8_t   kind;
    uint8_t   arr_state;  /* ENC_ARR_ST_*; arrays only */
} id_entry;

/* An array entry is PENDING while its first walk runs (no id yet, so a revisit
 * through an object or reference cycle emits by value), then CLAIMED with an id
 * or UNSHARED. An UNSHARED walk reached a PHP reference: COW separation keeps a
 * shared reference in each copy, so one decoded array for both owners would
 * lose the aliasing a later write through either copy depends on. */
enum { ENC_ARR_ST_CLAIMED, ENC_ARR_ST_PENDING, ENC_ARR_ST_UNSHARED };

/* encode_value_ex flags:
 * - ENC_RCN: reached through a shared container or object property, so object
 *   identity must be tracked.
 * - ENC_BORROWED: the encoder holds one extra ref on this zval (hook return,
 *   snapshot, or private dup); do not count it as TAG_SHARED_ARRAY sharing.
 * - ENC_SHARED_ARR: reached inside an ancestor array that is shared or
 *   immutable, so RC=1 strings within it may be revisited by pointer.
 * - ENC_SNAP_BORROWED: subset of ENC_BORROWED for snapshots/dups of an already
 *   rooted container (original owner + snapshot = 2 refs), so an array with
 *   refcount 2 is unshared externally and needs no nested zend_array_dup. */
#define ENC_RCN            1u
#define ENC_BORROWED       2u
#define ENC_SHARED_ARR     4u
#define ENC_SNAP_BORROWED  8u

#define ENC_DICT_INLINE_CAP    16u
#define ENC_ICACHE_INLINE_CAP  32u
#define ENC_ID_INLINE_CAP      16u
#define ENC_CE_CACHE_SIZE       4u

typedef struct {
    zend_class_entry *ce;
    zend_function *sleep_fn;
    uint32_t class_idx;   /* UINT32_MAX until interned */
    uint32_t slot_count;
    uint8_t slots_dense;  /* 1 when properties_info_table has no NULL holes */
} enc_ce_slot;

typedef struct {
    /* Pointer-keyed cache; NULL marks an empty slot. No eviction. */
    intern_slot *icache;
    intern_slot *icache_inline;
    uint32_t icache_mask;    /* capacity-1 (power of 2); 0 = unallocated */
    uint32_t icache_count;
    HashTable hash_map;
    uint8_t   hash_map_inited;   /* lazy init; disabled after excessive collisions */
    /* Exact negative filter over dict entries: skips zend_hash_find (and
     * DJBX33A hashing of unhashed dynamic strings) whenever length or first
     * byte cannot match any string in dict. */
    uint64_t dict_c0[4];
    uint64_t dict_len_mask;
    size_t   dict_max_len;
    uint8_t  dict_has_long;
    /* Per-payload id table for tracked (zend_object*|zend_reference*) entities. */
    id_entry *id_buckets;
    id_entry *id_inline;
    uint32_t id_mask;       /* capacity - 1, capacity is power of 2; 0 means unallocated */
    uint32_t id_count;
    uint32_t next_id;
    /* Once user code can run, every id-table entry owns a reference. */
    uint8_t pins_active;
    /* Top-level shared array being walked at depth 1; duplicated lazily in
     * enc_pins_activate_slow() before any hook can mutate SPL storage in place. */
    HashTable *top_ht;
    HashTable *top_dup;
    /* Elements already emitted under sub-linear tags; see
     * PHPSER_SUBLINEAR_MAX_ELEMS. */
    uint32_t sublinear_elems;
    uint32_t materialization_excess;
    /* IS_REFERENCE values visited so far; an array walk that moves it cannot
     * be shared (see ENC_ARR_ST_UNSHARED). */
    uint32_t refs_seen;
    zend_string **dict;
    zend_string **dict_inline;
    uint32_t dict_len;
    uint32_t dict_cap;
    uint32_t depth;
    /* A depth placeholder keeps the body well-formed but makes the frame
     * lossy; the caller must discard it. */
    uint8_t depth_exceeded;
    uint8_t stack_exhausted;
    /* Reject strings beyond the decoder's UINT32_MAX limit during encode. */
    uint8_t size_exceeded;
    /* An encode exception invalidates the entire frame, including session saves. */
    uint8_t failed;
    /* Set when the body uses a v2-only tag; header version becomes 0x02. */
    uint8_t wire_v2;
    /* 4-entry direct-mapped cache of per-CE __sleep, class_idx, and slot
     * layout across repeated objects of the same class. */
    enc_ce_slot ce_cache[ENC_CE_CACHE_SIZE];
} encode_ctx;

static zend_always_inline void enc_ctx_init(
    encode_ctx *e, zend_string **dict_inline,
    intern_slot *icache_inline, id_entry *id_inline)
{
    e->icache = NULL;
    e->icache_inline = icache_inline;
    e->icache_mask = 0;
    e->icache_count = 0;
    e->hash_map_inited = 0;
    e->dict_c0[0] = 0;
    e->dict_c0[1] = 0;
    e->dict_c0[2] = 0;
    e->dict_c0[3] = 0;
    e->dict_len_mask = 0;
    e->dict_max_len = 0;
    e->dict_has_long = 0;
    e->id_buckets = NULL;
    e->id_inline = id_inline;
    e->id_mask = 0;
    e->id_count = 0;
    e->next_id = 0;
    e->dict = dict_inline;
    e->dict_inline = dict_inline;
    e->dict_len = 0;
    e->dict_cap = ENC_DICT_INLINE_CAP;
    e->depth = 0;
    e->depth_exceeded = 0;
    e->stack_exhausted = 0;
    e->size_exceeded = 0;
    e->failed = 0;
    e->wire_v2 = 0;
    e->pins_active = 0;
    e->top_ht = NULL;
    e->top_dup = NULL;
    e->sublinear_elems = 0;
    e->materialization_excess = 0;
    e->refs_seen = 0;
    for (uint32_t i = 0; i < ENC_CE_CACHE_SIZE; i++) {
        e->ce_cache[i].ce = NULL;
    }
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

/* True when a materialized std properties table holds exactly the live
 * declared slots, each initialized, in slot order; *count gets their number.
 * rebuild_object_properties appends one IS_INDIRECT bucket per non-NULL
 * properties_info_table entry, in table (slot) order, and skips dead slots
 * that a redeclared inherited property leaves behind. Dynamic writes append
 * non-INDIRECT buckets and an unset leaves an INDIRECT-to-UNDEF one, so either
 * fails the lockstep compare. Deleting the last dynamic property trims
 * nNumUsed, so a hole-free table is the normal case. */
static zend_never_inline bool enc_props_mirror_slots(zend_object *obj, uint32_t *count)
{
    HashTable *ht = obj->properties;
    uint32_t pc = (uint32_t)obj->ce->default_properties_count;
    uint32_t n = ht->nNumUsed;
    if (n != ht->nNumOfElements || n > pc || HT_IS_PACKED(ht)) {
        return false;
    }
    zend_property_info **info = obj->ce->properties_info_table;
    zval *slot = obj->properties_table;
    Bucket *b = ht->arData;
    Bucket *end = b + n;
    for (uint32_t i = 0; i < pc; i++) {
        if (info[i] == NULL) continue;
        if (b == end || Z_TYPE(b->val) != IS_INDIRECT
            || Z_INDIRECT(b->val) != &slot[i] || Z_TYPE(slot[i]) == IS_UNDEF) {
            return false;
        }
        b++;
    }
    if (b != end) return false;
    *count = n;
    return true;
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

/* Immutable arrays are never freed during a request, so they carry no pin. */
static zend_always_inline void enc_id_release(id_entry *entry) {
    switch (entry->kind) {
        case ENC_ID_OBJECT:
            OBJ_RELEASE((zend_object *)entry->ptr);
            break;
        case ENC_ID_REFERENCE:
            GC_DTOR((zend_reference *)entry->ptr);
            break;
        case ENC_ID_ARRAY:
            GC_DTOR((zend_array *)entry->ptr);
            break;
        default:
            break;
    }
}

static zend_always_inline void enc_id_addref(uintptr_t ptr, uint8_t kind) {
    switch (kind) {
        case ENC_ID_OBJECT:
            GC_ADDREF((zend_object *)ptr);
            break;
        case ENC_ID_REFERENCE:
            GC_ADDREF((zend_reference *)ptr);
            break;
        case ENC_ID_ARRAY:
            GC_ADDREF((zend_array *)ptr);
            break;
        default:
            break;
    }
}

/* First user-code boundary: pin every tracked entity before the hook runs,
 * so a hook (or a destructor it triggers) cannot destroy a table entry and
 * let a same-address reallocation masquerade as a back-reference. Called
 * immediately before __serialize / __sleep / ce->serialize / lazy-object
 * initialization; hook-free payloads never pay the refcount traffic. */
static zend_never_inline void enc_pins_activate_slow(encode_ctx *e) {
    e->pins_active = 1;
    if (e->top_ht && !e->top_dup) {
        e->top_dup = zend_array_dup(e->top_ht);
    }
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

/* Releasing an encoder-held copy (private duplicate, property snapshot) with
 * zval_ptr_dtor drops refcounts to nonzero, and a full root buffer then runs a
 * cycle collection synchronously. Its destructors are user code, which could
 * free an unpinned id-table entry and reuse its address. Until pins activate no
 * user code has run, so every copied value still has its graph owner: drop the
 * copy's reference without the possible-root check. Net refcount is unchanged,
 * so no cycle candidate is lost. */
static zend_always_inline void enc_release_copy_zval(encode_ctx *e, zval *zv) {
    if (e->pins_active) {
        zval_ptr_dtor(zv);
    } else if (Z_REFCOUNTED_P(zv)) {
        uint32_t rc = GC_DELREF(Z_COUNTED_P(zv));
        ZEND_ASSERT(rc > 0);
        (void)rc;
    }
}

static void enc_release_dup(encode_ctx *e, HashTable *dup) {
    if (!e->pins_active) {
        zval *zv;
        ZEND_HASH_FOREACH_VAL(dup, zv) {
            enc_release_copy_zval(e, zv);
            ZVAL_UNDEF(zv);
        } ZEND_HASH_FOREACH_END();
    }
    zend_array_destroy(dup);
}

static void enc_ctx_destroy(encode_ctx *e) {
    if (e->top_dup) {
        enc_release_dup(e, e->top_dup);
        e->top_dup = NULL;
    }
    if (e->icache && e->icache != e->icache_inline) efree(e->icache);
    if (e->hash_map_inited) zend_hash_destroy(&e->hash_map);
    if (e->id_buckets) {
        if (e->pins_active) {
            for (uint32_t i = 0; i <= e->id_mask; i++) {
                if (e->id_buckets[i].ptr) enc_id_release(&e->id_buckets[i]);
            }
        }
        if (e->id_buckets != e->id_inline) efree(e->id_buckets);
    }
    for (uint32_t i = 0; i < e->dict_len; i++) {
        zend_string_release(e->dict[i]);
    }
    if (e->dict && e->dict != e->dict_inline) {
        efree(e->dict);
    }
}

/* Mix the pointer down: object/reference allocations are 8/16-byte aligned,
 * so the low bits are useless. A Fibonacci-style multiply spreads them. */
static zend_always_inline uint32_t id_hash(uintptr_t p) {
    return (uint32_t)((p * 11400714819323198485ULL) >> 32);
}

static zend_never_inline void enc_id_grow(encode_ctx *e) {
    if (!e->id_buckets) {
        memset(e->id_inline, 0, ENC_ID_INLINE_CAP * sizeof(id_entry));
        e->id_buckets = e->id_inline;
        e->id_mask = ENC_ID_INLINE_CAP - 1;
        return;
    }
    uint32_t old_cap = e->id_mask + 1;
    uint32_t new_cap = old_cap * (old_cap <= 256u ? 4u : 2u);
    id_entry *new_buckets = ecalloc(new_cap, sizeof(id_entry));
    uint32_t new_mask = new_cap - 1;
    for (uint32_t i = 0; i <= e->id_mask; i++) {
        uintptr_t p = e->id_buckets[i].ptr;
        if (!p) continue;
        uint32_t h = id_hash(p) & new_mask;
        while (new_buckets[h].ptr) h = (h + 1) & new_mask;
        new_buckets[h] = e->id_buckets[i];
    }
    if (e->id_buckets != e->id_inline) efree(e->id_buckets);
    e->id_buckets = new_buckets;
    e->id_mask = new_mask;
}

/* Look up before growing: a repeat adds no entry, even at the 50% load
 * boundary. Returned slots remain valid until the next id-table insertion. */
static inline id_entry *enc_id_slot(encode_ctx *e, uintptr_t ptr) {
    if (UNEXPECTED(!e->id_buckets)) enc_id_grow(e);
    uint32_t h = id_hash(ptr) & e->id_mask;
    while (e->id_buckets[h].ptr) {
        if (e->id_buckets[h].ptr == ptr) return &e->id_buckets[h];
        h = (h + 1) & e->id_mask;
    }
    if (UNEXPECTED((e->id_count + 1) * 2 > e->id_mask + 1)) {
        enc_id_grow(e);
        h = id_hash(ptr) & e->id_mask;
        while (e->id_buckets[h].ptr) h = (h + 1) & e->id_mask;
    }
    return &e->id_buckets[h];
}

/* Returns 1 for a new entity, 0 for a repeat; *out_id holds its encounter id. */
static inline int enc_visit(encode_ctx *e, void *ptr, uint8_t kind,
                            uint32_t *out_id) {
    uintptr_t pp = (uintptr_t)ptr;
    id_entry *entry = enc_id_slot(e, pp);
    if (entry->ptr) {
        *out_id = entry->id;
        return 0;
    }
    uint32_t id = e->next_id++;
    entry->ptr = pp;
    entry->id = id;
    entry->kind = kind;
    entry->arr_state = ENC_ARR_ST_CLAIMED;
    if (UNEXPECTED(e->pins_active)) enc_id_addref(pp, kind);
    e->id_count++;
    *out_id = id;
    return 1;
}

enum { ENC_ARR_NEW, ENC_ARR_REPEAT, ENC_ARR_BY_VALUE };

/* Array counterpart of enc_visit. ENC_ARR_NEW records a pending entry that
 * enc_array_done resolves; ENC_ARR_REPEAT writes the id for a TAG_REF;
 * ENC_ARR_BY_VALUE asks for a non-claiming walk. */
static inline int enc_visit_array(encode_ctx *e, HashTable *ht, uint32_t *out_id) {
    uintptr_t pp = (uintptr_t)ht;
    id_entry *entry = enc_id_slot(e, pp);
    if (entry->ptr) {
        ZEND_ASSERT(entry->kind == ENC_ID_ARRAY
            || entry->kind == ENC_ID_ARRAY_IMMUTABLE);
        if (entry->arr_state == ENC_ARR_ST_UNSHARED) {
            /* An earlier walk may have reached a reference through a new
             * object. That object now emits a back-reference, so retry the
             * array instead of expanding every later holder by value. */
            entry->arr_state = ENC_ARR_ST_PENDING;
            return ENC_ARR_NEW;
        }
        if (entry->arr_state != ENC_ARR_ST_CLAIMED) {
            return ENC_ARR_BY_VALUE;
        }
        *out_id = entry->id;
        return ENC_ARR_REPEAT;
    }
    uint8_t kind = (GC_FLAGS(ht) & GC_IMMUTABLE)
        ? ENC_ID_ARRAY_IMMUTABLE : ENC_ID_ARRAY;
    entry->ptr = pp;
    entry->id = 0;
    entry->kind = kind;
    entry->arr_state = ENC_ARR_ST_PENDING;
    if (UNEXPECTED(e->pins_active)) enc_id_addref(pp, kind);
    e->id_count++;
    return ENC_ARR_NEW;
}

/* Resolve a pending entry after its walk. A shareable array claims the next
 * id now, after every id its children claimed, which is where the decoder
 * registers it. The walk may have grown the table, so probe again. */
static void enc_array_done(encode_ctx *e, HashTable *ht, bool shareable) {
    uintptr_t pp = (uintptr_t)ht;
    uint32_t h = id_hash(pp) & e->id_mask;
    while (e->id_buckets[h].ptr != pp) {
        h = (h + 1) & e->id_mask;
    }
    ZEND_ASSERT(e->id_buckets[h].arr_state == ENC_ARR_ST_PENDING);
    if (shareable) {
        e->id_buckets[h].id = e->next_id++;
        e->id_buckets[h].arr_state = ENC_ARR_ST_CLAIMED;
    } else {
        e->id_buckets[h].arr_state = ENC_ARR_ST_UNSHARED;
    }
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

/* Each resize re-inserts every live entry, and nested payloads with a small
 * top level start unseeded at 32 slots. Quadruple through 2048 slots (at most
 * 8192, 128 KiB on 64-bit), then double so over-allocation stays bounded. */
#define ENC_ICACHE_QUAD_MAX 2048u

static zend_never_inline void enc_icache_grow(encode_ctx *e) {
    if (!e->icache) {
        memset(e->icache_inline, 0, ENC_ICACHE_INLINE_CAP * sizeof(intern_slot));
        e->icache = e->icache_inline;
        e->icache_mask = ENC_ICACHE_INLINE_CAP - 1;
        return;
    }
    uint32_t old_cap = e->icache_mask + 1;
    uint32_t new_cap = old_cap * (old_cap <= ENC_ICACHE_QUAD_MAX ? 4u : 2u);
    intern_slot *nb = ecalloc(new_cap, sizeof(intern_slot));  /* ptr==NULL = empty */
    uint32_t nm = new_cap - 1;
    for (uint32_t i = 0; i <= e->icache_mask; i++) {
        zend_string *p = e->icache[i].ptr;
        if (!p) continue;
        uint32_t h = id_hash((uintptr_t)p) & nm;
        while (nb[h].ptr) h = (h + 1) & nm;
        nb[h] = e->icache[i];
    }
    if (e->icache != e->icache_inline) efree(e->icache);
    e->icache = nb;
    e->icache_mask = nm;
}

/* Insert `zs` (which the caller has confirmed absent via enc_cache_find) and
 * return its slot. No eviction: entries track string pointer identities,
 * including first encounters emitted inline. The caller writes
 * .idx immediately; no enc_cache_alloc_slot call may intervene before that
 * write, so the returned pointer can't be invalidated by a rehash. */
static zend_always_inline intern_slot *enc_cache_alloc_slot(encode_ctx *e, zend_string *zs) {
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

static zend_always_inline bool enc_dict_may_contain(
    const encode_ctx *e, const zend_string *zs)
{
    size_t len = ZSTR_LEN(zs);
    if (len > e->dict_max_len) return false;
    if (len < 64) {
        if ((e->dict_len_mask & ((uint64_t)1u << len)) == 0) return false;
    } else if (!e->dict_has_long) {
        return false;
    }
    if (len > 0) {
        uint8_t c0 = (uint8_t)ZSTR_VAL(zs)[0];
        if ((e->dict_c0[c0 >> 6] & ((uint64_t)1u << (c0 & 63))) == 0) return false;
    }
    return true;
}

static void enc_dict_hash_add(encode_ctx *e, zend_string *zs, uint32_t idx) {
    HashTable *ht = &e->hash_map;
    if (UNEXPECTED(ht->nNumOfElements >= ENC_DICT_MAX_CHAIN_LENGTH)) {
        uint32_t bucket_idx = HT_HASH(ht, (uint32_t)zend_string_hash_val(zs) | ht->nTableMask);
        uint32_t remaining = ENC_DICT_MAX_CHAIN_LENGTH;
        while (bucket_idx != HT_INVALID_IDX) {
            if (UNEXPECTED(--remaining == 0)) {
                /* Content deduplication is optional. Keep pointer hits and
                 * allow duplicate dictionary entries instead of rejecting
                 * serializable input or walking unbounded collision chains. */
                zend_hash_destroy(ht);
                e->hash_map_inited = 0;
                return;
            }
            Bucket *bucket = HT_HASH_TO_BUCKET(ht, bucket_idx);
            bucket_idx = Z_NEXT(bucket->val);
        }
    }
    zval iz;
    ZVAL_LONG(&iz, idx);
    zend_hash_add(ht, zs, &iz);
}

/* Allocate a dict slot for `zs` and return its index. Maintains the content
 * hash_map once the dict crosses HASH_MAP_THRESHOLD entries; below that,
 * pointer equality already catches the literal-interned case. */
static uint32_t enc_dict_append(encode_ctx *e, zend_string *zs) {
    size_t len = ZSTR_LEN(zs);
    if (UNEXPECTED(len > UINT32_MAX)) e->size_exceeded = 1;
    if (UNEXPECTED(e->dict_len == e->dict_cap)) {
        uint32_t new_cap = e->dict_cap * 2;
        if (e->dict == e->dict_inline) {
            zend_string **heap = emalloc(new_cap * sizeof(zend_string *));
            memcpy(heap, e->dict_inline, e->dict_len * sizeof(zend_string *));
            e->dict = heap;
        } else {
            e->dict = erealloc(e->dict, new_cap * sizeof(zend_string *));
        }
        e->dict_cap = new_cap;
    }
    uint32_t idx = e->dict_len++;
    /* Own strings borrowed from hook temporaries until the dictionary header is
     * emitted. */
    e->dict[idx] = zend_string_copy(zs);

    if (len < 64) {
        e->dict_len_mask |= (uint64_t)1u << len;
    } else {
        e->dict_has_long = 1;
    }
    if (len > e->dict_max_len) e->dict_max_len = len;
    if (len > 0) {
        uint8_t c0 = (uint8_t)ZSTR_VAL(zs)[0];
        e->dict_c0[c0 >> 6] |= (uint64_t)1u << (c0 & 63);
    }

    if (e->dict_len == HASH_MAP_THRESHOLD) {
        /* Lazy init: pre-size the map to the threshold count to avoid an
         * internal rehash on the batch insert below. */
        zend_hash_init(&e->hash_map, HASH_MAP_THRESHOLD * 2, NULL, NULL, 0);
        e->hash_map_inited = 1;
        for (uint32_t i = 0; i < e->dict_len; i++) {
            zval iz; ZVAL_LONG(&iz, i);
            zend_hash_add(&e->hash_map, e->dict[i], &iz);
        }
    } else if (e->hash_map_inited) {
        enc_dict_hash_add(e, zs, idx);
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
    if (e->hash_map_inited && enc_dict_may_contain(e, zs)) {
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
    if (e->hash_map_inited && enc_dict_may_contain(e, zs)) {
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
 * rewriting earlier bytes. Non-interned RC=1 strings outside shared arrays
 * cannot revisit by pointer, so they bypass icache lookup and insertion while
 * still checking hash_map via enc_dict_may_contain for content hits. */
static zend_always_inline int enc_emit_str_tagged(
    enc_buf *body, encode_ctx *e, zend_string *zs,
    uint8_t dict_tag, uint8_t inline_tag, bool shared_arr)
{
    bool rc1_unique = !ZSTR_IS_INTERNED(zs) && GC_REFCOUNT(zs) == 1 && !shared_arr;
    intern_slot *s = NULL;
    if (!rc1_unique) {
        s = enc_cache_find(e, zs);
        if (s) {
            if (SLOT_IS_DICT(*s)) {
                emit_tag_and_varint(body, dict_tag, SLOT_DICT_IDX(*s));
                return 0;
            }
            /* INLINE_EMITTED: promote this cache slot, not the earlier wire bytes. */
            s->idx = enc_dict_append(e, zs);
            emit_tag_and_varint(body, dict_tag, s->idx);
            return 0;
        }
    }
    /* Cache miss above HASH_MAP_THRESHOLD: content dedup might still hit. */
    if (e->hash_map_inited && enc_dict_may_contain(e, zs)) {
        zval *hit = zend_hash_find(&e->hash_map, zs);
        if (hit) {
            uint32_t idx = (uint32_t)Z_LVAL_P(hit);
            if (!rc1_unique) enc_seed_dict_on_hit(e, s, zs, idx);
            emit_tag_and_varint(body, dict_tag, idx);
            return 0;
        }
    }
    size_t slen = ZSTR_LEN(zs);
    if (UNEXPECTED(slen > UINT32_MAX || slen > SIZE_MAX - 1 - VARINT_MAX_BYTES_U32)) {
        e->size_exceeded = 1;
        return -1;
    }
    enc_buf_reserve(body, 1 + VARINT_MAX_BYTES_U32 + slen);
    char *base = body->data;
    size_t pos = body->len;
    base[pos++] = (char)inline_tag;
    pos = varint_put(base, pos, slen);
    if (slen) memcpy(base + pos, ZSTR_VAL(zs), slen);
    body->len = pos + slen;
    if (!rc1_unique) {
        enc_cache_alloc_slot(e, zs)->idx = SLOT_INLINE_MARK;
    }
    return 0;
}

static zend_always_inline void enc_emit_str_value_ex(
    enc_buf *body, encode_ctx *e, zend_string *zs, bool shared_arr)
{
    if (UNEXPECTED(enc_emit_str_tagged(body, e, zs, TAG_STR_DICT, TAG_STR_INLINE, shared_arr) < 0)) {
        /* Keep the body structurally valid until the caller discards the frame. */
        enc_buf_appendc(body, TAG_NULL);
    }
}

/* Back-patch the count to avoid a second property walk. Counts >=128 need extra
 * varint bytes inserted into the body. */
static void enc_patch_nprops(enc_buf *body, size_t off, uint32_t nprops) {
    if (EXPECTED(nprops < 0x80)) {
        body->data[off] = (char)nprops;
        return;
    }
    char var[VARINT_MAX_BYTES];
    size_t len = varint_put(var, 0, nprops);
    size_t extra = len - 1;
    enc_buf_reserve(body, extra);
    char *base = body->data;
    size_t end = body->len;
    memmove(base + off + len, base + off + 1, end - (off + 1));
    memcpy(base + off, var, len);
    body->len = end + extra;
}

static zend_always_inline void enc_emit_str_key_ex(
    enc_buf *body, encode_ctx *e, zend_string *zs, bool shared_arr)
{
    (void)enc_emit_str_tagged(body, e, zs, KEY_STR, KEY_STR_INLINE, shared_arr);
}

/* -------------------------------------------------------------------------
 * Encode dispatch.
 * ------------------------------------------------------------------------- */

static void encode_value(enc_buf *body, encode_ctx *e, zval *v);
static void encode_value_ex(enc_buf *body, encode_ctx *e, zval *v,
                            uint32_t flags);
static void encode_value_inner(enc_buf *body, encode_ctx *e, zval *v,
                               uint32_t flags);
static zend_never_inline void encode_hashtable(enc_buf *body, encode_ctx *e, HashTable *ht,
                                               bool in_rcn_array, bool ht_shared,
                                               bool shared_arr);

ZEND_STATIC_ASSERT(IS_UNDEF == 0 && IS_NULL == 1 && IS_FALSE == 2 && IS_TRUE == 3
    && IS_LONG == 4 && IS_DOUBLE == 5 && IS_STRING == 6 && IS_ARRAY == 7,
    "Zend scalar type codes must precede IS_ARRAY");

/* Declared properties use IS_INDIRECT; uninitialized slots use IS_UNDEF. Skip
 * keyless and undefined buckets so emitted counts match values. */
static zend_always_inline zval *enc_obj_prop_val(Bucket *b) {
    if (!b->key) return NULL;
    zval *v = &b->val;
    if (Z_TYPE_P(v) == IS_INDIRECT) v = Z_INDIRECT_P(v);
    if (Z_TYPE_P(v) == IS_UNDEF) return NULL;
    return v;
}

/* Inline leaf path for container children: scalars and strings cannot recurse,
 * so they skip depth++/--, C-stack checks, and IS_INDIRECT derefs. Check type
 * first so container children don't pay the OOM/depth check twice. */
static zend_always_inline void encode_value_hot(
    enc_buf *body, encode_ctx *e, zval *v, uint32_t flags)
{
    uint8_t t = Z_TYPE_P(v);
    if (t <= IS_STRING) {
        if (UNEXPECTED(e->failed || PHPSER_HAS_OOM() || e->depth >= MAX_DEPTH)) {
            encode_value_ex(body, e, v, flags);
            return;
        }
        switch (t) {
            case IS_UNDEF:
            case IS_NULL:
                enc_buf_appendc(body, TAG_NULL);
                return;
            case IS_FALSE:
                enc_buf_appendc(body, TAG_FALSE);
                return;
            case IS_TRUE:
                enc_buf_appendc(body, TAG_TRUE);
                return;
            case IS_LONG:
                emit_tag_and_varint(body, TAG_LONG, zigzag_encode64(Z_LVAL_P(v)));
                return;
            case IS_DOUBLE:
                enc_buf_append_tag_le64(body, TAG_DOUBLE, Z_DVAL_P(v));
                return;
            case IS_STRING:
                enc_emit_str_value_ex(body, e, Z_STR_P(v), (flags & ENC_SHARED_ARR) != 0);
                return;
        }
    }
    encode_value_ex(body, e, v, flags);
}

static void encode_value(enc_buf *body, encode_ctx *e, zval *v) {
    encode_value_ex(body, e, v, 0);
}


static void encode_value_ex(enc_buf *body, encode_ctx *e, zval *v,
                            uint32_t flags) {
    /* After encoding fails, placeholders preserve container counts until the
     * caller discards the frame. */
    if (UNEXPECTED(e->failed || PHPSER_HAS_OOM())) {
        e->failed = 1;
        enc_buf_appendc(body, TAG_NULL);
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
        enc_buf_appendc(body, TAG_NULL);
        return;
    }
#ifdef ZEND_CHECK_STACK_LIMIT
    /* MAX_DEPTH levels need about 250 KiB of C stack on release builds, more
     * than a musl thread's 128 KiB default. */
    if (UNEXPECTED(e->depth >= PHPSER_STACK_CHECK_DEPTH)
        && UNEXPECTED(zend_call_stack_overflowed(EG(stack_limit)))) {
        e->stack_exhausted = 1;
        e->failed = 1;
        enc_buf_appendc(body, TAG_NULL);
        return;
    }
#endif
    e->depth++;
    encode_value_inner(body, e, v, flags);
    e->depth--;
}

enum { ENC_SLEEP_MISS, ENC_SLEEP_FOUND, ENC_SLEEP_UNINIT };

/* One lookup of native's php_var_serialize_try_add_sleep_prop: an
 * uninitialized typed slot counts as found-but-omitted, an unset untyped one
 * as missing so the next mangling is tried. */
static int enc_sleep_classify(zend_object *obj, zval *p, zval **out)
{
    if (p == NULL) return ENC_SLEEP_MISS;
    if (Z_TYPE_P(p) == IS_INDIRECT && Z_TYPE_P(Z_INDIRECT_P(p)) == IS_UNDEF) {
        return zend_get_typed_property_info_for_slot(obj, Z_INDIRECT_P(p))
            ? ENC_SLEEP_UNINIT : ENC_SLEEP_MISS;
    }
    *out = p;
    return ENC_SLEEP_FOUND;
}

static int enc_sleep_try_key(HashTable *props, zend_object *obj,
                             const char *key, size_t len, zval **out)
{
    return enc_sleep_classify(obj, zend_hash_str_find(props, key, len), out);
}

/* True when every live bucket of `props` is one of obj's declared slots, so
 * its key set is exactly the slot names of obj->ce. */
static bool enc_sleep_props_declared_only(HashTable *props, zend_object *obj)
{
    if (props != obj->properties
        || obj->handlers->get_properties != zend_std_get_properties
        || obj->handlers->get_properties_for != NULL
        || HT_IS_PACKED(props)) {
        return false;
    }
    Bucket *b = props->arData;
    Bucket *end = b + props->nNumUsed;
    for (; b < end; b++) {
        if (Z_TYPE(b->val) == IS_UNDEF) continue;
        if (Z_TYPE(b->val) != IS_INDIRECT) return false;
    }
    return true;
}

/* Resolve a __sleep name the way native serialize() does: the name as given,
 * then mangled private to the object's own class, then mangled protected.
 * Private properties of a parent class never match an unmangled name. On
 * ENC_SLEEP_FOUND, *out is the bucket value (possibly IS_INDIRECT).
 * *declared_only caches enc_sleep_props_declared_only (-1 = not computed). */
static int enc_sleep_find_prop(HashTable *props, zend_object *obj,
                               zend_string *name, int *declared_only,
                               zval **out)
{
    int r = enc_sleep_classify(obj, zend_hash_find(props, name), out);
    if (r != ENC_SLEEP_MISS || EG(exception)) return r;

    /* Declared non-public names skip building mangled keys: the declaration's
     * own name is the key native would build. A private declared by obj's
     * class is exactly the second candidate. A protected one is the third,
     * and the second cannot exist when the table holds only declared slots:
     * a protected property cannot be redeclared private. Every other outcome
     * falls through to the literal three-candidate walk. */
    zend_property_info *info = zend_hash_find_ptr(&obj->ce->properties_info, name);
    if (info != NULL && !(info->flags & ZEND_ACC_STATIC)) {
        bool own_private = (info->flags & ZEND_ACC_PRIVATE) && info->ce == obj->ce;
        bool is_protected = (info->flags & ZEND_ACC_PROTECTED) != 0;
        if (is_protected && *declared_only < 0) {
            *declared_only = enc_sleep_props_declared_only(props, obj);
        }
        if (own_private || (is_protected && *declared_only)) {
            r = enc_sleep_classify(
                obj, zend_hash_find_known_hash(props, info->name), out);
            if (r != ENC_SLEEP_MISS) return r;
        }
    }

    zend_string *cname = obj->ce->name;
    size_t cap = 3 + ZSTR_LEN(cname) + ZSTR_LEN(name);
    char stack_buf[256];
    char *buf = cap <= sizeof(stack_buf) ? stack_buf : emalloc(cap);

    buf[0] = '\0';
    memcpy(buf + 1, ZSTR_VAL(cname), ZSTR_LEN(cname));
    buf[1 + ZSTR_LEN(cname)] = '\0';
    memcpy(buf + 2 + ZSTR_LEN(cname), ZSTR_VAL(name), ZSTR_LEN(name));
    r = enc_sleep_try_key(props, obj, buf,
                          2 + ZSTR_LEN(cname) + ZSTR_LEN(name), out);
    if (r == ENC_SLEEP_MISS) {
        buf[0] = '\0';
        buf[1] = '*';
        buf[2] = '\0';
        memcpy(buf + 3, ZSTR_VAL(name), ZSTR_LEN(name));
        r = enc_sleep_try_key(props, obj, buf, 3 + ZSTR_LEN(name), out);
    }
    if (buf != stack_buf) efree(buf);
    return r;
}

#if PHP_VERSION_ID >= 80400
/* A nested hook can reset a proxy and free the owner of IS_INDIRECT slots.
 * Recover it without initializing a backing instance that skips serialization
 * initialization. Keep this rare lookup out of the generic encoder. */
static zend_never_inline ZEND_COLD zend_object *enc_pin_lazy_instance(zend_object *obj, HashTable *props)
{
    zval *bv;
    ZEND_HASH_FOREACH_VAL(props, bv) {
        if (Z_TYPE_P(bv) != IS_INDIRECT) continue;
        zval *slot = Z_INDIRECT_P(bv);
        zend_property_info *pi =
            zend_lazy_object_get_property_info_for_slot(obj, slot);
        if (!pi) return NULL;
        zend_object *inst = (zend_object *)((char *)slot - pi->offset);
        GC_ADDREF(inst);
        return inst;
    } ZEND_HASH_FOREACH_END();
    return NULL;
}
#endif

/* Keep the __sleep snapshot's key/value ownership and cleanup rules together. */
static zend_never_inline void enc_encode_sleep_object(
    enc_buf *body, encode_ctx *e, zend_object *obj,
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
                PHPSER_ZSTR_FMT "::__sleep() should return an array only containing the names of instance-variables to serialize",
                PHPSER_ZSTR_ARG(obj->ce->name));
        }
        zval_ptr_dtor(&names_zv);
        enc_unvisit_last(e, obj, identity_tracked);
        if (EG(exception)) e->failed = 1;
        enc_buf_appendc(body, TAG_NULL);
        return;
    }

    HashTable *names_ht = Z_ARRVAL(names_zv);
    /* Resolve names against the serialize-purpose property table, as native
     * does: it initializes lazy objects (honoring
     * SKIP_INITIALIZATION_ON_SERIALIZE), reads a proxy's real instance, and
     * pins the table so hook-triggered writes COW-separate. */
    zval obj_zv;
    ZVAL_OBJ(&obj_zv, obj);
    HashTable *props = zend_get_properties_for(&obj_zv, ZEND_PROP_PURPOSE_SERIALIZE);
    if (UNEXPECTED(EG(exception))) {
        if (props) zend_release_properties(props);
        zval_ptr_dtor(&names_zv);
        enc_unvisit_last(e, obj, identity_tracked);
        e->failed = 1;
        enc_buf_appendc(body, TAG_NULL);
        return;
    }
    zend_object *inst = NULL;
#if PHP_VERSION_ID >= 80400
    if (props && zend_object_is_lazy_proxy(obj) && zend_lazy_object_initialized(obj)) {
        inst = enc_pin_lazy_instance(obj, props);
    }
#endif
    /* Fix the selected member set at __sleep-return time. Own both keys and
     * values because emitting an earlier value can run a nested hook that
     * mutates the object or replaces a referenced name in `names_ht`. */
    zend_string **snap_keys = NULL;
    zval *snap_vals = NULL;
    uint32_t nprops = 0, snap_cap = 0;
    /* One bit per props bucket: native keys its member table by the resolved
     * name, so a second name resolving to the same bucket is a duplicate. */
    uint64_t seen_stack[4] = {0};
    uint64_t *seen = seen_stack;
    uint32_t seen_words = sizeof(seen_stack) / sizeof(seen_stack[0]);
    if (props && props->nNumUsed > seen_words * 64) {
        seen_words = (props->nNumUsed + 63) / 64;
        seen = ecalloc(seen_words, sizeof(uint64_t));
    }
    int declared_only = -1;
    zval *zv_name;
    ZEND_HASH_FOREACH_VAL_IND(names_ht, zv_name) {
        if (UNEXPECTED(PHPSER_HAS_OOM())) {
            e->failed = 1;
            break;
        }
        ZVAL_DEREF(zv_name);
        if (Z_TYPE_P(zv_name) != IS_STRING) {
            php_error_docref(NULL, E_WARNING,
                PHPSER_ZSTR_FMT "::__sleep() should return an array only containing the names of instance-variables to serialize",
                PHPSER_ZSTR_ARG(obj->ce->name));
        }
        zend_string *tmp_name;
        zend_string *nm = zval_get_tmp_string(zv_name, &tmp_name);

        zval *p = NULL;
        int found = props
            ? enc_sleep_find_prop(props, obj, nm, &declared_only, &p)
            : ENC_SLEEP_MISS;
        if (found == ENC_SLEEP_MISS) {
            if (UNEXPECTED(EG(exception))) {
                zend_tmp_string_release(tmp_name);
                e->failed = 1;
                break;
            }
            php_error_docref(NULL, E_WARNING,
                "\"" PHPSER_ZSTR_FMT "\" returned as member variable from __sleep() but does not exist",
                PHPSER_ZSTR_ARG(nm));
            zend_tmp_string_release(tmp_name);
            continue;
        }
        if (found == ENC_SLEEP_UNINIT) {
            zend_tmp_string_release(tmp_name);
            continue;
        }

        /* A hit never comes from a packed table (string lookup), so `p` is
         * the val member at offset 0 of its Bucket. */
        ZEND_ASSERT(offsetof(Bucket, val) == 0 && !HT_IS_PACKED(props));
        Bucket *b = (Bucket *)p;
        uint32_t bi = (uint32_t)(b - props->arData);
        ZEND_ASSERT(b->key != NULL);
        if (UNEXPECTED(bi / 64 >= seen_words)) {
            /* The pinned table cannot grow, but never index past the map. */
            uint32_t words = bi / 64 + 1;
            uint64_t *grown = ecalloc(words, sizeof(uint64_t));
            memcpy(grown, seen, seen_words * sizeof(uint64_t));
            if (seen != seen_stack) efree(seen);
            seen = grown;
            seen_words = words;
        }
        if (seen[bi / 64] & ((uint64_t)1 << (bi % 64))) {
            php_error_docref(NULL, E_WARNING,
                "\"" PHPSER_ZSTR_FMT "\" is returned from __sleep() multiple times",
                PHPSER_ZSTR_ARG(nm));
            zend_tmp_string_release(tmp_name);
            continue;
        }
        seen[bi / 64] |= (uint64_t)1 << (bi % 64);
        if (Z_TYPE_P(p) == IS_INDIRECT) p = Z_INDIRECT_P(p);

        if (nprops == snap_cap) {
            snap_cap = snap_cap ? snap_cap * 2 : 8;
            snap_keys = erealloc(
                snap_keys, snap_cap * sizeof(zend_string *));
            snap_vals = erealloc(snap_vals, snap_cap * sizeof(zval));
        }
        /* The bucket key, not the sleep name: it is the table's own (usually
         * interned) string, so repeated objects share one dict entry. */
        snap_keys[nprops] = zend_string_copy(b->key);
        ZVAL_COPY(&snap_vals[nprops], p);
        nprops++;
        zend_tmp_string_release(tmp_name);
    } ZEND_HASH_FOREACH_END();
    if (seen != seen_stack) efree(seen);
    if (props) zend_release_properties(props);
    if (inst) OBJ_RELEASE(inst);

    emit_tag_and_varint(body, TAG_OBJECT, class_idx);
    varint_write_u64(body, nprops);
    /* The frame is discarded once encoding fails, so skip the remaining
     * names: interning them all after a pending memory_limit error can pass
     * the hard limit. */
    for (uint32_t i = 0; i < nprops && !e->failed; i++) {
        if (UNEXPECTED(PHPSER_HAS_OOM())) {
            e->failed = 1;
            break;
        }
        varint_write_u64(body, enc_intern_zstr(e, snap_keys[i]));
        encode_value_hot(body, e, &snap_vals[i], ENC_RCN | ENC_BORROWED);
    }
    for (uint32_t i = 0; i < nprops; i++) {
        zend_string_release(snap_keys[i]);
        zval_ptr_dtor(&snap_vals[i]);
    }
    if (snap_keys) efree(snap_keys);
    if (snap_vals) efree(snap_vals);
    zval_ptr_dtor(&names_zv);
}

static zend_always_inline enc_ce_slot *enc_ce_cache_get(encode_ctx *e, zend_class_entry *ce) {
    enc_ce_slot *cs = &e->ce_cache[(((uintptr_t)ce) >> 6) & (ENC_CE_CACHE_SIZE - 1)];
    if (EXPECTED(cs->ce == ce)) return cs;
    cs->ce = ce;
    cs->class_idx = UINT32_MAX;
    cs->sleep_fn = NULL;
    if (ce->__serialize == NULL && ce->serialize == NULL
        && !(ce->ce_flags & ZEND_ACC_ENUM)) {
        zval *sf = zend_hash_find_known_hash(
            &ce->function_table, ZSTR_KNOWN(ZEND_STR_SLEEP));
        if (sf) cs->sleep_fn = Z_FUNC_P(sf);
    }
    uint32_t sc = ce_table_slot_count(ce);
    cs->slot_count = sc;
    cs->slots_dense = (sc == (uint32_t)ce->default_properties_count) ? 1 : 0;
    return cs;
}

/* Outlined so zval snap_stack[4] stays off encode_value_ex's recursive frame. */
static zend_never_inline void enc_emit_slots_snap(
    enc_buf *body, encode_ctx *e, zend_class_entry *ce, zval *ptbl,
    int start_pi, int pc, uint32_t snap_n, bool slots_dense,
    uint32_t prop_flags)
{
    zval snap_stack[4];
    zval *snap = snap_n <= 4 ? snap_stack
        : (zval *)safe_emalloc(snap_n, sizeof(zval), 0);
    if (EXPECTED(slots_dense)) {
        for (uint32_t k = 0; k < snap_n; k++) {
            ZVAL_COPY(&snap[k], &ptbl[start_pi + (int)k]);
        }
    } else {
        uint32_t k = 0;
        for (int pj = start_pi; pj < pc; pj++) {
            if (ce->properties_info_table[pj] == NULL) continue;
            ZVAL_COPY(&snap[k++], &ptbl[pj]);
        }
        ZEND_ASSERT(k == snap_n);
    }
    uint32_t sflags = prop_flags | ENC_BORROWED | ENC_SNAP_BORROWED;
    for (uint32_t k = 0; k < snap_n && !e->failed; k++) {
        encode_value_hot(body, e, &snap[k], sflags);
    }
    for (uint32_t k = 0; k < snap_n; k++) {
        enc_release_copy_zval(e, &snap[k]);
    }
    if (snap != snap_stack) efree(snap);
}

static void encode_value_inner(enc_buf *body, encode_ctx *e, zval *v,
                               uint32_t flags) {
    bool in_rcn_array = (flags & ENC_RCN) != 0;
    switch (Z_TYPE_P(v)) {
        case IS_UNDEF:
        case IS_NULL:
            enc_buf_appendc(body, TAG_NULL);
            return;
        case IS_FALSE:
            enc_buf_appendc(body, TAG_FALSE);
            return;
        case IS_TRUE:
            enc_buf_appendc(body, TAG_TRUE);
            return;
        case IS_LONG:
            emit_tag_and_varint(body, TAG_LONG, zigzag_encode64(Z_LVAL_P(v)));
            return;
        case IS_DOUBLE:
            enc_buf_append_tag_le64(body, TAG_DOUBLE, Z_DVAL_P(v));
            return;
        case IS_STRING:
            enc_emit_str_value_ex(body, e, Z_STR_P(v), (flags & ENC_SHARED_ARR) != 0);
            return;
        case IS_ARRAY: {
            HashTable *ht = Z_ARRVAL_P(v);
            if (zend_hash_num_elements(ht) == 0) {
                enc_buf_reserve(body, 2);
                body->data[body->len++] = (char)TAG_ASSOC;
                body->data[body->len++] = 0;
                return;
            }
            /* Measure sharing before our protective addref obscures external
             * ownership. A snapshot/dup of an already-rooted element holds 1
             * ref plus the original owner's 1 ref (own_refs == 2), valid only
             * while no userland hook has run to drop the original owner's ref. */
            bool ht_immutable = (GC_FLAGS(ht) & GC_IMMUTABLE) != 0;
            uint32_t own_refs =
                ((flags & ENC_SNAP_BORROWED) && !e->pins_active) ? 2u : 1u;
            bool ht_shared = !ht_immutable && GC_REFCOUNT(ht) > own_refs;
            bool ht_claimable = ht_immutable
                || (!ht_immutable && GC_REFCOUNT(ht)
                    > ((flags & ENC_BORROWED) ? 2u : 1u));
            bool claimed = false;
            if (ht_claimable && e->depth > 1) {
                uint32_t id;
                int visit = enc_visit_array(e, ht, &id);
                if (visit == ENC_ARR_REPEAT) {
                    emit_tag_and_varint(body, TAG_REF, id);
                    return;
                }
                if (visit == ENC_ARR_NEW) {
                    claimed = true;
                }
            }
            size_t prefix_off = 0;
            uint32_t refs_before = e->refs_seen;
            if (claimed) {
                prefix_off = body->len;
                enc_buf_appendc(body, TAG_SHARED_ARRAY);
            }
            bool children_in_rcn_array = in_rcn_array || ht_shared;
            bool children_shared_arr = ((flags & ENC_SHARED_ARR) != 0)
                || ht_shared || ht_immutable;
            GC_TRY_ADDREF(ht);
            encode_hashtable(body, e, ht, children_in_rcn_array, ht_shared,
                             children_shared_arr);
            if (claimed) {
                bool shareable = e->refs_seen == refs_before;
                enc_array_done(e, ht, shareable);
                if (shareable) {
                    e->wire_v2 = 1;
                } else {
                    char *base = body->data;
                    size_t end = body->len;
                    memmove(base + prefix_off, base + prefix_off + 1,
                            end - prefix_off - 1);
                    body->len = end - 1;
                }
            }
            if (!ht_immutable && !GC_DELREF(ht)) {
                zend_array_destroy(ht);
            }
            return;
        }
        case IS_REFERENCE: {
            zend_reference *ref = Z_REF_P(v);
            uint32_t id;
            e->refs_seen++;
            if (!enc_visit(e, ref, ENC_ID_REFERENCE, &id)) {
                emit_tag_and_varint(body, TAG_REF, id);
                return;
            }
            enc_buf_appendc(body, TAG_NEW_REF);
            encode_value_ex(body, e, Z_REFVAL_P(v),
                            ENC_RCN | (flags & ENC_SHARED_ARR));
            return;
        }
        case IS_OBJECT: {
            zend_object *obj = Z_OBJ_P(v);
            zend_class_entry *ce = obj->ce;
            if (UNEXPECTED(ce->ce_flags & ZEND_ACC_NOT_SERIALIZABLE)) {
                zend_throw_exception_ex(zend_ce_exception, 0,
                    "Serialization of '" PHPSER_ZSTR_FMT "' is not allowed",
                    PHPSER_ZSTR_ARG(ce->name));
                e->failed = 1;
                enc_buf_appendc(body, TAG_NULL);
                return;
            }
            enc_ce_slot *cs = EXPECTED(ce != PHP_IC_ENTRY)
                ? enc_ce_cache_get(e, ce) : NULL;
            zend_function *sleep_fn = cs ? cs->sleep_fn : NULL;
            bool identity_tracked = in_rcn_array
                || GC_REFCOUNT(obj) > 1
                || (obj->properties && GC_REFCOUNT(obj->properties) > 1)
                || ce->__serialize != NULL
                || ce->serialize != NULL
                || sleep_fn != NULL;
            if (identity_tracked) {
                uint32_t id;
                if (!enc_visit(e, obj, ENC_ID_OBJECT, &id)) {
                    emit_tag_and_varint(body, TAG_REF, id);
                    return;
                }
            } else {
                e->next_id++;
            }
            /* Legacy C-level serializer (Serializable, SplPriorityQueue,
             * SplMinHeap, ...). As in PHP, __serialize takes precedence. */
            if (UNEXPECTED(ce->__serialize == NULL && ce->serialize != NULL)) {
#ifdef ZEND_CHECK_STACK_LIMIT
                if (UNEXPECTED(zend_call_stack_overflowed(EG(stack_limit)))) {
                    enc_unvisit_last(e, obj, identity_tracked);
                    e->stack_exhausted = 1;
                    e->failed = 1;
                    enc_buf_appendc(body, TAG_NULL);
                    return;
                }
#endif
                unsigned char *data = NULL;
                size_t len = 0;
                zval obj_zv;
                ZVAL_OBJ(&obj_zv, obj);
                enc_pins_activate(e);
                if (ce->serialize(&obj_zv, &data, &len, NULL) != SUCCESS) {
                    if (data) efree(data);
                    enc_unvisit_last(e, obj, identity_tracked);
                    if (EG(exception)) e->failed = 1;
                    enc_buf_appendc(body, TAG_NULL);
                    return;
                }
                if (UNEXPECTED(EG(exception))) {
                    if (data) efree(data);
                    enc_unvisit_last(e, obj, identity_tracked);
                    e->failed = 1;
                    enc_buf_appendc(body, TAG_NULL);
                    return;
                }
                if (UNEXPECTED(len > UINT32_MAX)) {
                    e->size_exceeded = 1;
                    if (data) efree(data);
                    enc_unvisit_last(e, obj, identity_tracked);
                    enc_buf_appendc(body, TAG_NULL);
                    return;
                }
                uint32_t class_idx = cs->class_idx;
                if (UNEXPECTED(class_idx == UINT32_MAX)) {
                    class_idx = cs->class_idx = enc_intern_zstr(e, ce->name);
                }
                emit_tag_and_varint(body, TAG_OBJECT_LEGACY, class_idx);
                varint_write_u64(body, len);
                if (len > 0 && data) enc_buf_appendl(body, (const char *)data, len);
                if (data) efree(data);
                return;
            }
            /* __serialize() takes precedence over property iteration. */
            if (UNEXPECTED(ce->__serialize != NULL)) {
#ifdef ZEND_CHECK_STACK_LIMIT
                if (UNEXPECTED(zend_call_stack_overflowed(EG(stack_limit)))) {
                    enc_unvisit_last(e, obj, identity_tracked);
                    e->stack_exhausted = 1;
                    e->failed = 1;
                    enc_buf_appendc(body, TAG_NULL);
                    return;
                }
#endif
                zval retval;
                ZVAL_UNDEF(&retval);
                enc_pins_activate(e);
                zend_call_known_instance_method_with_0_params(
                    ce->__serialize, obj, &retval);
                if (UNEXPECTED(EG(exception)) || Z_TYPE(retval) != IS_ARRAY) {
                    if (!EG(exception) && Z_TYPE(retval) != IS_ARRAY) {
                        zend_type_error(
                            PHPSER_ZSTR_FMT "::__serialize() must return an array",
                            PHPSER_ZSTR_ARG(ce->name));
                    }
                    zval_ptr_dtor(&retval);
                    enc_unvisit_last(e, obj, identity_tracked);
                    if (EG(exception)) e->failed = 1;
                    enc_buf_appendc(body, TAG_NULL);
                    return;
                }
                uint32_t class_idx = cs->class_idx;
                if (UNEXPECTED(class_idx == UINT32_MAX)) {
                    class_idx = cs->class_idx = enc_intern_zstr(e, ce->name);
                }
                emit_tag_and_varint(body, TAG_OBJECT_MAGIC, class_idx);
                encode_value_ex(body, e, &retval, ENC_BORROWED);
                zval_ptr_dtor(&retval);
                return;
            }
            /* Enums are singletons object_init_ex can't recreate. */
            if (UNEXPECTED(ce->ce_flags & ZEND_ACC_ENUM)) {
                uint32_t class_idx = cs->class_idx;
                if (UNEXPECTED(class_idx == UINT32_MAX)) {
                    class_idx = cs->class_idx = enc_intern_zstr(e, ce->name);
                }
                zval *cname = zend_enum_fetch_case_name(obj);
                uint32_t case_idx = enc_intern_zstr(e, Z_STR_P(cname));
                emit_tag_and_varint(body, TAG_ENUM, class_idx);
                varint_write_u64(body, case_idx);
                return;
            }
            uint32_t class_idx;
            if (EXPECTED(cs != NULL)) {
                class_idx = cs->class_idx;
                if (UNEXPECTED(class_idx == UINT32_MAX)) {
                    class_idx = cs->class_idx = enc_intern_zstr(e, ce->name);
                }
            } else {
                zend_string *ic_name = php_lookup_class_name(obj);
                class_idx = enc_intern_zstr(e, ic_name ? ic_name : ce->name);
                if (ic_name) zend_string_release(ic_name);
            }
            if (UNEXPECTED(sleep_fn != NULL)) {
#ifdef ZEND_CHECK_STACK_LIMIT
                if (UNEXPECTED(zend_call_stack_overflowed(EG(stack_limit)))) {
                    enc_unvisit_last(e, obj, identity_tracked);
                    e->stack_exhausted = 1;
                    e->failed = 1;
                    enc_buf_appendc(body, TAG_NULL);
                    return;
                }
#endif
                enc_encode_sleep_object(
                    body, e, obj, sleep_fn, class_idx, identity_tracked);
                return;
            }
            /* Direct slots avoid materializing a properties HashTable. */
            bool props_shared = ((flags & ENC_SHARED_ARR) != 0)
                || (obj->properties && !(GC_FLAGS(obj->properties) & GC_IMMUTABLE)
                    && GC_REFCOUNT(obj->properties) > 1);
            uint32_t prop_flags = ENC_RCN | (props_shared ? ENC_SHARED_ARR : 0u);
            uint32_t mirror_count = 0;
            if (EXPECTED(obj->handlers->get_properties == zend_std_get_properties)
                && EXPECTED(obj->handlers->get_properties_for == NULL)
#if PHP_VERSION_ID >= 80400
                && EXPECTED(!zend_object_is_lazy(obj))
#endif
                && EXPECTED(ce->__unserialize == NULL)
                && (EXPECTED(obj->properties == NULL)
                    || enc_props_mirror_slots(obj, &mirror_count))
                ) {
                int pc = ce->default_properties_count;
                bool slots_dense = cs ? (cs->slots_dense != 0) : false;
                bool slots_ok = true;
                uint32_t slot_count = mirror_count;
                zval *ptbl = obj->properties_table;
                if (EXPECTED(obj->properties == NULL)) {
                    if (EXPECTED(slots_dense)) {
                        for (int pi = 0; pi < pc; pi++) {
                            if (UNEXPECTED(Z_TYPE(ptbl[pi]) == IS_UNDEF)) {
                                slots_ok = false;
                                break;
                            }
                        }
                        slot_count = (uint32_t)pc;
                    } else {
                        for (int pi = 0; pi < pc; pi++) {
                            zend_property_info *info = ce->properties_info_table[pi];
                            if (info == NULL) continue;
                            if (UNEXPECTED(Z_TYPE(ptbl[pi]) == IS_UNDEF)) {
                                slots_ok = false;
                                break;
                            }
                            slot_count++;
                        }
                    }
                }
                if (EXPECTED(slots_ok)) {
                    uint32_t fp_nprops = slot_count;
                    e->wire_v2 = 1;
                    enc_buf_reserve(body, 1 + 2 * VARINT_MAX_BYTES_U32);
                    char *base = body->data;
                    size_t pos = body->len;
                    base[pos++] = (char)TAG_OBJECT_SLOTS;
                    pos = varint_put(base, pos, class_idx);
                    pos = varint_put(base, pos, fp_nprops);
                    body->len = pos;
                    /* A hook can mutate later slots. Snapshot from the first
                     * hook-capable value onward; preceding scalars, strings,
                     * empty arrays, and enums cannot re-enter PHP. A trailing
                     * array slot needs no snapshot: case IS_ARRAY addrefs it
                     * immediately and never reads `v` again. */
                    uint32_t emitted = 0;
                    if (EXPECTED(slots_dense)) {
                        for (int pi = 0; pi < pc; pi++) {
                            if (UNEXPECTED(e->failed)) break;
                            zval *pv = &ptbl[pi];
                            if (Z_TYPE_P(pv) >= IS_ARRAY) {
                                bool need_snap = true;
                                if (Z_TYPE_P(pv) == IS_ARRAY) {
                                    if (zend_hash_num_elements(Z_ARRVAL_P(pv)) == 0
                                        || emitted + 1 == fp_nprops) {
                                        need_snap = false;
                                    }
                                } else if (Z_TYPE_P(pv) == IS_OBJECT
                                           && (Z_OBJCE_P(pv)->ce_flags & ZEND_ACC_ENUM)) {
                                    need_snap = false;
                                }
                                if (need_snap) {
                                    enc_emit_slots_snap(body, e, ce, ptbl, pi, pc,
                                                        fp_nprops - emitted, true,
                                                        prop_flags);
                                    break;
                                }
                            }
                            encode_value_hot(body, e, pv, prop_flags);
                            emitted++;
                        }
                    } else {
                        for (int pi = 0; pi < pc; pi++) {
                            if (UNEXPECTED(e->failed)) break;
                            zend_property_info *info = ce->properties_info_table[pi];
                            if (info == NULL) continue;
                            zval *pv = &ptbl[pi];
                            if (Z_TYPE_P(pv) >= IS_ARRAY) {
                                bool need_snap = true;
                                if (Z_TYPE_P(pv) == IS_ARRAY) {
                                    if (zend_hash_num_elements(Z_ARRVAL_P(pv)) == 0
                                        || emitted + 1 == fp_nprops) {
                                        need_snap = false;
                                    }
                                } else if (Z_TYPE_P(pv) == IS_OBJECT
                                           && (Z_OBJCE_P(pv)->ce_flags & ZEND_ACC_ENUM)) {
                                    need_snap = false;
                                }
                                if (need_snap) {
                                    enc_emit_slots_snap(body, e, ce, ptbl, pi, pc,
                                                        fp_nprops - emitted, false,
                                                        prop_flags);
                                    break;
                                }
                            }
                            encode_value_hot(body, e, pv, prop_flags);
                            emitted++;
                        }
                    }
                    return;
                }
                /* Uninitialized typed slot: keyed TAG_OBJECT, skip IS_UNDEF. */
                emit_tag_and_varint(body, TAG_OBJECT, class_idx);
                size_t nprops_off = body->len;
                enc_buf_appendc(body, 0);
                uint32_t fp_nprops = 0;
                for (int pi = 0; pi < pc && !e->failed; pi++) {
                    if (UNEXPECTED(PHPSER_HAS_OOM())) {
                        e->failed = 1;
                        break;
                    }
                    zend_property_info *info = ce->properties_info_table[pi];
                    if (info == NULL) continue;
                    zval *p = &ptbl[pi];
                    if (Z_TYPE_P(p) == IS_UNDEF) continue;
                    varint_write_u64(body, enc_intern_zstr(e, info->name));
                    encode_value_hot(body, e, p, prop_flags);
                    fp_nprops++;
                }
                enc_patch_nprops(body, nprops_off, fp_nprops);
                return;
            }
            /* zend_get_properties_for pins the table, forcing hook-triggered writes
             * to COW-separate, and initializes lazy objects. */
            if (obj->handlers->get_properties != zend_std_get_properties
                || obj->handlers->get_properties_for != NULL
#if PHP_VERSION_ID >= 80400
                || zend_object_is_lazy(obj)
#endif
                ) {
                enc_pins_activate(e);
            }
            zval obj_zv;
            ZVAL_OBJ(&obj_zv, obj);
            HashTable *props = zend_get_properties_for(&obj_zv, ZEND_PROP_PURPOSE_SERIALIZE);
            if (UNEXPECTED(EG(exception))) {
                zend_release_properties(props);
                enc_unvisit_last(e, obj, identity_tracked);
                e->failed = 1;
                enc_buf_appendc(body, TAG_NULL);
                return;
            }
            zend_object *inst = NULL;
#if PHP_VERSION_ID >= 80400
            if (props && zend_object_is_lazy_proxy(obj) && zend_lazy_object_initialized(obj)) {
                inst = enc_pin_lazy_instance(obj, props);
            }
#endif
            emit_tag_and_varint(body, TAG_OBJECT, class_idx);
            size_t nprops_off = body->len;
            enc_buf_appendc(body, 0);
            uint32_t nprops = 0;
            bool skip_magic = UNEXPECTED(ce == PHP_IC_ENTRY);
            if (props) {
                Bucket *b = props->arData;
                Bucket *end = b + props->nNumUsed;
                for (; b < end && !e->failed; b++) {
                    zval *pv = enc_obj_prop_val(b);
                    if (!pv) continue;
                    if (skip_magic && b->key
                        && zend_string_equals_literal(b->key, MAGIC_MEMBER)) {
                        continue;
                    }
                    varint_write_u64(body, enc_intern_zstr(e, b->key));
                    encode_value_hot(body, e, pv, prop_flags);
                    nprops++;
                }
            }
            enc_patch_nprops(body, nprops_off, nprops);
            zend_release_properties(props);
            if (inst) OBJ_RELEASE(inst);
            return;
        }
        default:
            enc_buf_appendc(body, TAG_NULL);
            return;
    }
}

static zend_never_inline bool enc_key_is_numeric(zend_string *key) {
    zend_ulong index;
    return ZEND_HANDLE_NUMERIC(key, index);
}

static zend_always_inline bool enc_key_is_schema_eligible(zend_string *key) {
    return EXPECTED((unsigned char)ZSTR_VAL(key)[0] > '9')
        || !enc_key_is_numeric(key);
}

static void enc_write_key_idx_run(enc_buf *body, const uint32_t *key_idx, uint32_t ncols) {
    enc_buf_reserve(body, (size_t)ncols * VARINT_MAX_BYTES_U32);
    char *base = body->data;
    size_t pos = body->len;
    for (uint32_t i = 0; i < ncols; i++) {
        pos = varint_put(base, pos, key_idx[i]);
    }
    body->len = pos;
}

static void enc_prebind_equal_packed_string_arrays(
    encode_ctx *e, zval **cells, uint32_t nrows)
{
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
        if (UNEXPECTED(PHPSER_HAS_OOM())) {
            e->failed = 1;
            return;
        }
        enc_intern_zstr(e, Z_STR(values[i]));
    }
}

#define TABLE_STRING_CARDINALITY_MAX 8
#define TABLE_STACK_COLS 32u

static void enc_emit_table_string_column(
    enc_buf *body, encode_ctx *e, zval **cells, uint32_t nrows, bool col_shared)
{
    zend_string *unique[TABLE_STRING_CARDINALITY_MAX];
    uint32_t unique_idx[TABLE_STRING_CARDINALITY_MAX];
    uint8_t row_u_stack[1024];
    uint8_t *row_u = nrows <= sizeof(row_u_stack)
        ? row_u_stack : (uint8_t *)emalloc(nrows);
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
                    row_u[r] = (uint8_t)u;
                }
            } else {
                row_u[r] = (uint8_t)u;
            }
        }
        if (all_dict) {
            intern_slot *s = enc_cache_find(e, sr);
            if (!s || !SLOT_IS_DICT(*s)) {
                all_dict = 0;
            }
        }
        if (cardinality_overflow && !all_dict) {
            if (row_u != row_u_stack) efree(row_u);
            goto emit_mixed_strings;
        }
    }

    if (all_dict) {
        if (row_u != row_u_stack) efree(row_u);
        enc_buf_reserve(body, 1 + (size_t)nrows * VARINT_MAX_BYTES_U32);
        char *base = body->data;
        size_t pos = body->len;
        base[pos++] = (char)TAG_PACKED_STRINGS;
        for (uint32_t r = 0; r < nrows; r++) {
            intern_slot *sl = enc_cache_find(e, Z_STR_P(cells[r]));
            pos = varint_put(base, pos, SLOT_DICT_IDX(*sl));
        }
        body->len = pos;
        return;
    }

    if (!cardinality_overflow && n_unique < nrows) {
        for (uint32_t u = 0; u < n_unique; u++) {
            if (UNEXPECTED(PHPSER_HAS_OOM())) {
                if (row_u != row_u_stack) efree(row_u);
                e->failed = 1;
                return;
            }
            unique_idx[u] = enc_intern_zstr(e, unique[u]);
        }
        if (n_unique == 1) {
            if (row_u != row_u_stack) efree(row_u);
            uint32_t idx0 = unique_idx[0];
            if (EXPECTED(idx0 < 0x80)) {
                enc_buf_reserve(body, 1 + (size_t)nrows);
                body->data[body->len++] = (char)TAG_PACKED_STRINGS;
                memset(body->data + body->len, (int)idx0, nrows);
                body->len += nrows;
            } else {
                enc_buf_reserve(body, 1 + (size_t)nrows * VARINT_MAX_BYTES_U32);
                char *base = body->data;
                size_t pos = body->len;
                base[pos++] = (char)TAG_PACKED_STRINGS;
                for (uint32_t r = 0; r < nrows; r++) {
                    pos = varint_put(base, pos, idx0);
                }
                body->len = pos;
            }
            return;
        }
        enc_buf_reserve(body, 1 + (size_t)nrows * VARINT_MAX_BYTES_U32);
        char *base = body->data;
        size_t pos = body->len;
        base[pos++] = (char)TAG_PACKED_STRINGS;
        for (uint32_t r = 0; r < nrows; r++) {
            pos = varint_put(base, pos, unique_idx[row_u[r]]);
        }
        body->len = pos;
        if (row_u != row_u_stack) efree(row_u);
        return;
    }

    if (row_u != row_u_stack) efree(row_u);

emit_mixed_strings:
    enc_buf_appendc(body, TAG_PACKED_MIXED);
    if (UNEXPECTED(e->failed || PHPSER_HAS_OOM())) {
        e->failed = 1;
        return;
    }
    if (UNEXPECTED(e->depth >= MAX_DEPTH)) {
        for (uint32_t r = 0; r < nrows; r++) {
            encode_value_ex(body, e, cells[r], ENC_RCN);
        }
        return;
    }
    for (uint32_t r = 0; r < nrows; r++) {
        if (UNEXPECTED(e->failed || PHPSER_HAS_OOM())) {
            e->failed = 1;
            return;
        }
        enc_emit_str_value_ex(body, e, Z_STR_P(cells[r]), col_shared);
    }
}

/* Release the per-row pins taken by enc_try_table's gather. */
static void enc_free_row_pins(encode_ctx *e, HashTable **row_pin, uint32_t n_used) {
    for (uint32_t r = 0; r < n_used; r++) {
        if (row_pin[r] && !GC_DELREF(row_pin[r])) {
            enc_release_dup(e, row_pin[r]);
        }
    }
    efree(row_pin);
}

static zend_never_inline int enc_try_table(
    enc_buf *body, encode_ctx *e, zval *zp, uint32_t n_used, bool shared_arr)
{
    if (n_used < 2 || Z_TYPE(zp[0]) != IS_ARRAY || Z_TYPE(zp[1]) != IS_ARRAY) {
        return 0;
    }
    HashTable *ht0 = Z_ARRVAL(zp[0]);
    if (HT_IS_PACKED(ht0) || ht0->nNumOfElements == 0) {
        return 0;
    }
    uint32_t ncols = ht0->nNumOfElements;
    HashTable *ht1 = Z_ARRVAL(zp[1]);
    if (HT_IS_PACKED(ht1) || ht1->nNumOfElements != ncols) {
        return 0;
    }
    uint64_t ncells = (uint64_t)ncols * n_used;
    if (ncells > UINT32_MAX || ncells > SIZE_MAX / sizeof(zval *)) {
        return 0;
    }
    uint64_t row_bytes = phpser_mixed_array_bytes(ncols) + 48;
    uint64_t wire_credit = (uint64_t)ncols * PHPSER_MATERIALIZE_RATIO;
    uint64_t excess = row_bytes > wire_credit ? row_bytes - wire_credit : 0;
    uint32_t available = PHPSER_MATERIALIZE_ALLOWANCE - e->materialization_excess;
    if (excess > available || (excess != 0 && n_used > available / excess)) {
        return 0;
    }

    zend_string *k0_stack[TABLE_STACK_COLS];
    uint32_t key_idx_stack[TABLE_STACK_COLS];
    uint32_t col_mask_stack[TABLE_STACK_COLS];
    zend_string **k0 = ncols <= TABLE_STACK_COLS
        ? k0_stack : (zend_string **)safe_emalloc((size_t)ncols, sizeof(zend_string *), 0);
    uint32_t *col_mask = ncols <= TABLE_STACK_COLS
        ? col_mask_stack : (uint32_t *)safe_emalloc((size_t)ncols, sizeof(uint32_t), 0);
    zval **col_cells = NULL;
    HashTable **row_pin = NULL;
    uint8_t *row_dup = NULL;

    /* Validate row 0 keys and seed column type masks. */
    uint32_t col = 0;
    Bucket *b0 = ht0->arData;
    Bucket *end0 = b0 + ht0->nNumUsed;
    bool any_container = false;
    bool any_shared_row = ((GC_FLAGS(ht0) & GC_IMMUTABLE) != 0) || GC_REFCOUNT(ht0) > 1;
    for (; b0 < end0; b0++) {
        uint8_t t = Z_TYPE(b0->val);
        if (t == IS_UNDEF) continue;
        if (!b0->key || !enc_key_is_schema_eligible(b0->key)) goto fail;
        k0[col] = b0->key;
        col_mask[col] = (uint32_t)1u << t;
        if (t >= IS_ARRAY) any_container = true;
        col++;
    }
    if (UNEXPECTED(col != ncols)) goto fail;

    /* Validate row 1 keys BEFORE allocating col_cells so heterogeneous arrays
     * fail fast with zero heap allocations. */
    {
        Bucket *b = ht1->arData;
        Bucket *end = b + ht1->nNumUsed;
        uint32_t matched = 0;
        for (; b < end; b++) {
            uint8_t t = Z_TYPE(b->val);
            if (t == IS_UNDEF) continue;
            if (matched >= ncols || !b->key) goto fail;
            if (b->key != k0[matched] && !zend_string_equals(b->key, k0[matched])) {
                goto fail;
            }
            col_mask[matched] |= (uint32_t)1u << t;
            if (t >= IS_ARRAY) any_container = true;
            matched++;
        }
        if (UNEXPECTED(matched != ncols)) goto fail;
        if (((GC_FLAGS(ht1) & GC_IMMUTABLE) != 0) || GC_REFCOUNT(ht1) > 1) {
            any_shared_row = true;
        }
    }

    col_cells = (zval **)safe_emalloc((size_t)ncols * n_used, sizeof(zval *), 0);

    /* Gather row 0 and row 1 into column-major storage. */
    {
        Bucket *b = ht0->arData;
        Bucket *end = b + ht0->nNumUsed;
        uint32_t c = 0;
        for (; b < end; b++) {
            if (Z_TYPE(b->val) == IS_UNDEF) continue;
            col_cells[(size_t)c * n_used] = &b->val;
            c++;
        }
        b = ht1->arData;
        end = b + ht1->nNumUsed;
        c = 0;
        for (; b < end; b++) {
            if (Z_TYPE(b->val) == IS_UNDEF) continue;
            col_cells[(size_t)c * n_used + 1] = &b->val;
            c++;
        }
    }

    /* Fused schema validation + gather + type-mask pass for rows 2..n_used-1. */
    for (uint32_t r = 2; r < n_used; r++) {
        if (UNEXPECTED(Z_TYPE(zp[r]) != IS_ARRAY)) goto fail;
        HashTable *ht = Z_ARRVAL(zp[r]);
        if (UNEXPECTED(HT_IS_PACKED(ht) || ht->nNumOfElements != ncols)) goto fail;
        if (((GC_FLAGS(ht) & GC_IMMUTABLE) != 0) || GC_REFCOUNT(ht) > 1) {
            any_shared_row = true;
        }
        Bucket *b = ht->arData;
        Bucket *end = b + ht->nNumUsed;
        uint32_t c = 0;
        for (; b < end; b++) {
            uint8_t t = Z_TYPE(b->val);
            if (t == IS_UNDEF) continue;
            if (UNEXPECTED(c >= ncols || !b->key)) goto fail;
            if (UNEXPECTED(b->key != k0[c] && !zend_string_equals(b->key, k0[c]))) {
                goto fail;
            }
            col_mask[c] |= (uint32_t)1u << t;
            if (t >= IS_ARRAY) any_container = true;
            col_cells[(size_t)c * n_used + r] = &b->val;
            c++;
        }
        if (UNEXPECTED(c != ncols)) goto fail;
    }

    /* Schema confirmed across all rows: charge budget and intern column names. */
    e->materialization_excess += (uint32_t)(n_used * excess);

    uint32_t *key_idx = ncols <= TABLE_STACK_COLS
        ? key_idx_stack : (uint32_t *)safe_emalloc((size_t)ncols, sizeof(uint32_t), 0);
    for (uint32_t c = 0; c < ncols; c++) {
        if (UNEXPECTED(PHPSER_HAS_OOM())) {
            if (key_idx != key_idx_stack) efree(key_idx);
            efree(col_cells);
            if (k0 != k0_stack) efree(k0);
            if (col_mask != col_mask_stack) efree(col_mask);
            e->failed = 1;
            return 1;
        }
        key_idx[c] = enc_intern_zstr(e, k0[c]);
    }
    if (k0 != k0_stack) efree(k0);
    k0 = NULL;

    /* Only container-bearing tables can trigger userland hooks during column
     * emission; scalar/string tables skip per-row pinning and duplication. */
    if (any_container) {
        for (uint32_t r = 0; r < n_used; r++) {
            if (UNEXPECTED(PHPSER_HAS_OOM())) {
                if (key_idx != key_idx_stack) efree(key_idx);
                efree(col_cells);
                if (row_pin) enc_free_row_pins(e, row_pin, n_used);
                if (row_dup) efree(row_dup);
                if (col_mask != col_mask_stack) efree(col_mask);
                e->failed = 1;
                return 1;
            }
            HashTable *ht = Z_ARRVAL(zp[r]);
            HashTable *pinned = NULL;
            if (!(GC_FLAGS(ht) & GC_IMMUTABLE)) {
                if (GC_REFCOUNT(ht) > 1) {
                    pinned = zend_array_dup(ht);
                    if (!row_dup) row_dup = (uint8_t *)ecalloc(n_used, 1);
                    row_dup[r] = 1;
                    Bucket *b = pinned->arData;
                    Bucket *end = b + pinned->nNumUsed;
                    uint32_t c = 0;
                    for (; b < end && c < ncols; b++) {
                        if (Z_TYPE(b->val) == IS_UNDEF) continue;
                        col_cells[(size_t)c * n_used + r] = &b->val;
                        c++;
                    }
                    ZEND_ASSERT(c == ncols);
                } else {
                    GC_ADDREF(ht);
                    pinned = ht;
                }
            }
            if (pinned) {
                if (!row_pin) {
                    row_pin = (HashTable **)ecalloc(n_used, sizeof(HashTable *));
                }
                row_pin[r] = pinned;
            }
        }
    }

    e->wire_v2 = 1;
    enc_buf_reserve(body, 1 + 2 * VARINT_MAX_BYTES_U32);
    {
        char *base = body->data;
        size_t pos = body->len;
        base[pos++] = (char)TAG_TABLE;
        pos = varint_put(base, pos, n_used);
        pos = varint_put(base, pos, ncols);
        body->len = pos;
    }
    enc_write_key_idx_run(body, key_idx, ncols);
    if (key_idx != key_idx_stack) efree(key_idx);

    bool col_shared = shared_arr || any_shared_row;
    const uint32_t trivial_scalar_mask =
        (1u << IS_NULL) | (1u << IS_FALSE) | (1u << IS_TRUE);

    for (uint32_t c = 0; c < ncols; c++) {
        if (UNEXPECTED(e->failed || PHPSER_HAS_OOM())) {
            e->failed = 1;
            break;
        }
        zval **cells = &col_cells[(size_t)c * n_used];
        uint32_t mask = col_mask[c];
        if (mask == (1u << IS_LONG)) {
            uint8_t col_tag = TAG_PACKED_LONGS;
            if (n_used >= 4) {
                uint64_t prev = (uint64_t)Z_LVAL_P(cells[0]);
                uint64_t plain_bytes = varint_len_u64(zigzag_encode64((int64_t)prev));
                uint64_t delta_bytes = plain_bytes;
                for (uint32_t r = 1; r < n_used; r++) {
                    uint64_t cur = (uint64_t)Z_LVAL_P(cells[r]);
                    plain_bytes += varint_len_u64(zigzag_encode64((int64_t)cur));
                    delta_bytes += varint_len_u64(zigzag_encode64((int64_t)(cur - prev)));
                    prev = cur;
                }
                if (delta_bytes < plain_bytes) col_tag = TAG_PACKED_DELTA;
            }
            enc_buf_reserve(body, 1 + (size_t)n_used * VARINT_MAX_BYTES);
            char *base = body->data;
            size_t pos = body->len;
            base[pos++] = (char)col_tag;
            if (col_tag == TAG_PACKED_DELTA) {
                uint64_t prev = (uint64_t)Z_LVAL_P(cells[0]);
                pos = varint_put(base, pos, zigzag_encode64((int64_t)prev));
                for (uint32_t r = 1; r < n_used; r++) {
                    uint64_t cur = (uint64_t)Z_LVAL_P(cells[r]);
                    pos = varint_put(base, pos, zigzag_encode64((int64_t)(cur - prev)));
                    prev = cur;
                }
            } else {
                for (uint32_t r = 0; r < n_used; r++) {
                    pos = varint_put(base, pos, zigzag_encode64(Z_LVAL_P(cells[r])));
                }
            }
            body->len = pos;
        } else if (mask == (1u << IS_DOUBLE)) {
            enc_buf_reserve(body, 1 + (size_t)n_used * 8);
            char *base = body->data;
            size_t pos = body->len;
            base[pos++] = (char)TAG_PACKED_DOUBLES;
            for (uint32_t r = 0; r < n_used; r++) {
                pos = le64_put(base, pos, Z_DVAL_P(cells[r]));
            }
            body->len = pos;
        } else if (mask == (1u << IS_STRING)) {
            enc_emit_table_string_column(body, e, cells, n_used, col_shared);
        } else if ((mask & ~trivial_scalar_mask) == 0 && e->depth < MAX_DEPTH) {
            enc_buf_reserve(body, 1 + (size_t)n_used);
            body->data[body->len++] = (char)TAG_PACKED_MIXED;
            if ((mask & (mask - 1)) == 0) {
                uint8_t tag = (uint8_t)(Z_TYPE_P(cells[0]) - 1u);
                memset(body->data + body->len, tag, n_used);
                body->len += n_used;
            } else {
                char *dst = body->data + body->len;
                for (uint32_t r = 0; r < n_used; r++) {
                    dst[r] = (char)(Z_TYPE_P(cells[r]) - 1u);
                }
                body->len += n_used;
            }
        } else {
            if (mask == (1u << IS_ARRAY)) {
                enc_prebind_equal_packed_string_arrays(e, cells, n_used);
                if (UNEXPECTED(e->failed)) break;
            }
            enc_buf_appendc(body, TAG_PACKED_MIXED);
            uint32_t base_flags = ENC_RCN | (col_shared ? ENC_SHARED_ARR : 0u);
            for (uint32_t r = 0; r < n_used; r++) {
                if (UNEXPECTED(e->failed || PHPSER_HAS_OOM())) {
                    e->failed = 1;
                    break;
                }
                uint32_t cflags = (row_dup && row_dup[r])
                    ? (base_flags | ENC_BORROWED | ENC_SNAP_BORROWED) : base_flags;
                encode_value_hot(body, e, cells[r], cflags);
            }
        }
    }

    efree(col_cells);
    if (row_pin) enc_free_row_pins(e, row_pin, n_used);
    if (row_dup) efree(row_dup);
    if (col_mask != col_mask_stack) efree(col_mask);
    return 1;

fail:
    if (col_cells) efree(col_cells);
    if (k0 != k0_stack) efree(k0);
    if (col_mask != col_mask_stack) efree(col_mask);
    return 0;
}

/* Single-sweep packed numeric run classifier: fuses type validation, affine
 * check, and plain-vs-delta sizing into one linear pass over zp[0..n_used-1]. */
static uint8_t detect_packed_run(encode_ctx *e, HashTable *ht, uint32_t n_used,
                                 int64_t *out_step) {
    zval *zp = ht->arPacked;
    uint8_t t0 = Z_TYPE(zp[0]);
    if (t0 == IS_LONG) {
        if (n_used < 4) {
            for (uint32_t i = 1; i < n_used; i++) {
                if (Z_TYPE(zp[i]) != IS_LONG) return TAG_PACKED_MIXED;
            }
            return TAG_PACKED_LONGS;
        }
        if (Z_TYPE(zp[1]) != IS_LONG) return TAG_PACKED_MIXED;
        uint64_t v0 = (uint64_t)Z_LVAL(zp[0]);
        uint64_t v1 = (uint64_t)Z_LVAL(zp[1]);
        uint64_t step0 = v1 - v0;
        uint64_t prev = v1;
        uint32_t i = 2;
        for (; i < n_used; i++) {
            if (UNEXPECTED(Z_TYPE(zp[i]) != IS_LONG)) return TAG_PACKED_MIXED;
            uint64_t cur = (uint64_t)Z_LVAL(zp[i]);
            if (cur - prev != step0) goto size_delta;
            prev = cur;
        }
        if (n_used <= PHPSER_SUBLINEAR_MAX_ELEMS
            && e->sublinear_elems <= PHPSER_SUBLINEAR_MAX_ELEMS - n_used) {
            e->sublinear_elems += n_used;
            *out_step = (int64_t)step0;
            return TAG_PACKED_AFFINE;
        }
    size_delta:;
        uint64_t plain_bytes = varint_len_u64(zigzag_encode64((int64_t)v0));
        uint64_t delta_bytes = plain_bytes
            + (uint64_t)(i - 1) * varint_len_u64(zigzag_encode64((int64_t)step0));
        for (uint32_t j = 1; j < i; j++) {
            plain_bytes += varint_len_u64(zigzag_encode64(Z_LVAL(zp[j])));
        }
        for (; i < n_used; i++) {
            if (UNEXPECTED(Z_TYPE(zp[i]) != IS_LONG)) return TAG_PACKED_MIXED;
            uint64_t cur = (uint64_t)Z_LVAL(zp[i]);
            plain_bytes += varint_len_u64(zigzag_encode64((int64_t)cur));
            delta_bytes += varint_len_u64(zigzag_encode64((int64_t)(cur - prev)));
            prev = cur;
        }
        return delta_bytes < plain_bytes ? TAG_PACKED_DELTA : TAG_PACKED_LONGS;
    }
    if (t0 == IS_DOUBLE) {
        for (uint32_t i = 1; i < n_used; i++) {
            if (UNEXPECTED(Z_TYPE(zp[i]) != IS_DOUBLE)) return TAG_PACKED_MIXED;
        }
        return TAG_PACKED_DOUBLES;
    }
    return TAG_PACKED_MIXED;
}

/* A private duplicate holds one reference to every element it copied alongside
 * the original table's reference (ENC_SNAP_BORROWED). */
static zend_always_inline uint32_t enc_walk_flags(
    bool in_rcn_array, bool shared_arr, HashTable *dup)
{
    return (in_rcn_array ? ENC_RCN : 0u)
        | (shared_arr ? ENC_SHARED_ARR : 0u)
        | (dup ? (ENC_BORROWED | ENC_SNAP_BORROWED) : 0u);
}

static zend_never_inline void encode_top_shared_packed(
    enc_buf *body, encode_ctx *e, HashTable *ht,
    bool in_rcn_array, bool shared_arr, bool sparse);
static zend_never_inline void encode_top_shared_assoc(
    enc_buf *body, encode_ctx *e, HashTable *ht,
    bool in_rcn_array, bool shared_arr, bool emit_keys);

static zend_never_inline void encode_hashtable(enc_buf *body, encode_ctx *e, HashTable *ht,
                                               bool in_rcn_array, bool ht_shared,
                                               bool shared_arr) {
    uint32_t n_used = ht->nNumUsed;
    uint32_t n_elems = ht->nNumOfElements;
    int is_packed = HT_IS_PACKED(ht);

    if (is_packed && n_used == n_elems && n_used > 0) {
        zval *zp = ht->arPacked;
        if (Z_TYPE(zp[0]) == IS_STRING) {
            size_t tag_off = body->len;
            enc_buf_reserve(body, 1 + VARINT_MAX_BYTES_U32 + (size_t)n_used * VARINT_MAX_BYTES_U32);
            char *base = body->data;
            size_t pos = tag_off;
            base[pos++] = (char)TAG_PACKED_STRINGS;
            pos = varint_put(base, pos, n_elems);
            const size_t run_start = pos;
            for (uint32_t i = 0; i < n_used; i++) {
                uint32_t idx;
                if (Z_TYPE(zp[i]) != IS_STRING
                    || !enc_str_dict_idx(e, Z_STR(zp[i]), &idx)) {
                    body->data[tag_off] = (char)TAG_PACKED_MIXED;
                    body->len = run_start;
                    if (UNEXPECTED(ht_shared && e->depth == 1)) {
                        encode_top_shared_packed(body, e, ht, in_rcn_array,
                                                 shared_arr, false);
                        return;
                    }
                    /* Defer zend_array_dup until the first hook-capable value. */
                    HashTable *dup = NULL;
                    zval *wzp = zp;
                    uint32_t wflags = enc_walk_flags(in_rcn_array, shared_arr, NULL);
                    for (uint32_t j = 0; j < n_used; j++) {
                        if (ht_shared && dup == NULL && Z_TYPE(wzp[j]) >= IS_ARRAY) {
                            dup = zend_array_dup(ht);
                            wzp = dup->arPacked;
                            wflags = enc_walk_flags(in_rcn_array, shared_arr, dup);
                        }
                        encode_value_hot(body, e, &wzp[j], wflags);
                        if (UNEXPECTED(e->failed)) break;
                    }
                    if (dup) enc_release_dup(e, dup);
                    return;
                }
                pos = varint_put(base, pos, idx);
            }
            body->len = pos;
            return;
        }
        /* Dense packed, non-string lead: try a numeric typed-run tag. */
        int64_t affine_step = 0;
        uint8_t tag = detect_packed_run(e, ht, n_used, &affine_step);
        if (tag == TAG_PACKED_MIXED && enc_try_table(body, e, zp, n_used, shared_arr)) {
            return;
        }
        if (tag == TAG_PACKED_AFFINE || tag == TAG_PACKED_DELTA) {
            e->wire_v2 = 1;
        }
        emit_tag_and_varint(body, tag, n_elems);
        if (tag == TAG_PACKED_AFFINE) {
            enc_buf_reserve(body, 2 * VARINT_MAX_BYTES);
            char *base = body->data;
            size_t pos = body->len;
            pos = varint_put(base, pos, zigzag_encode64(Z_LVAL(zp[0])));
            pos = varint_put(base, pos, zigzag_encode64(affine_step));
            body->len = pos;
        } else if (tag == TAG_PACKED_DELTA) {
            enc_buf_reserve(body, (size_t)n_used * VARINT_MAX_BYTES);
            char *base = body->data;
            size_t pos = body->len;
            uint64_t prev = (uint64_t)Z_LVAL(zp[0]);
            pos = varint_put(base, pos, zigzag_encode64((int64_t)prev));
            for (uint32_t i = 1; i < n_used; i++) {
                uint64_t cur = (uint64_t)Z_LVAL(zp[i]);
                pos = varint_put(base, pos, zigzag_encode64((int64_t)(cur - prev)));
                prev = cur;
            }
            body->len = pos;
        } else if (tag == TAG_PACKED_LONGS) {
            enc_buf_reserve(body, (size_t)n_used * VARINT_MAX_BYTES);
            char *base = body->data;
            size_t pos = body->len;
            for (uint32_t i = 0; i < n_used; i++) {
                pos = varint_put(base, pos, zigzag_encode64(Z_LVAL(zp[i])));
            }
            body->len = pos;
        } else if (tag == TAG_PACKED_DOUBLES) {
            enc_buf_reserve(body, (size_t)n_used * 8);
            char *base = body->data;
            size_t pos = body->len;
            for (uint32_t i = 0; i < n_used; i++) {
                pos = le64_put(base, pos, Z_DVAL(zp[i]));
            }
            body->len = pos;
        } else {
            if (UNEXPECTED(ht_shared && e->depth == 1)) {
                encode_top_shared_packed(body, e, ht, in_rcn_array,
                                         shared_arr, false);
                return;
            }
            HashTable *dup = NULL;
            zval *wzp = zp;
            uint32_t wflags = enc_walk_flags(in_rcn_array, shared_arr, NULL);
            for (uint32_t i = 0; i < n_used; i++) {
                if (ht_shared && dup == NULL && Z_TYPE(wzp[i]) >= IS_ARRAY) {
                    dup = zend_array_dup(ht);
                    wzp = dup->arPacked;
                    wflags = enc_walk_flags(in_rcn_array, shared_arr, dup);
                }
                encode_value_hot(body, e, &wzp[i], wflags);
                if (UNEXPECTED(e->failed)) break;
            }
            if (dup) enc_release_dup(e, dup);
        }
        return;
    }

    if (is_packed) {
        /* Sparse packed (post-unset). Preserve original int keys. */
        emit_tag_and_varint(body, TAG_ASSOC, n_elems);
        if (UNEXPECTED(ht_shared && e->depth == 1)) {
            encode_top_shared_packed(body, e, ht, in_rcn_array,
                                     shared_arr, true);
            return;
        }
        HashTable *dup = NULL;
        zval *zp = ht->arPacked;
        uint32_t wflags = enc_walk_flags(in_rcn_array, shared_arr, NULL);
        for (uint32_t i = 0; i < n_used; i++) {
            if (Z_TYPE(zp[i]) == IS_UNDEF) continue;
            if (ht_shared && dup == NULL && Z_TYPE(zp[i]) >= IS_ARRAY) {
                dup = zend_array_dup(ht);
                zp = dup->arPacked;
                wflags = enc_walk_flags(in_rcn_array, shared_arr, dup);
            }
            emit_tag_and_varint(body, KEY_LONG, zigzag_encode64((int64_t)i));
            encode_value_hot(body, e, &zp[i], wflags);
            if (UNEXPECTED(e->failed)) break;
        }
        if (dup) enc_release_dup(e, dup);
        return;
    }

    /* Non-packed (assoc). Speculatively emit TAG_ASSOC_DICT in one pass when
     * the dictionary is non-empty, rewinding body->len on a key miss. */
    if (e->dict_len > 0 && n_elems > 0) {
        size_t save_len = body->len;
        enc_buf_reserve(body, 1 + VARINT_MAX_BYTES_U32 + (size_t)n_elems * VARINT_MAX_BYTES_U32);
        char *base = body->data;
        size_t pos = save_len;
        base[pos++] = (char)TAG_ASSOC_DICT;
        pos = varint_put(base, pos, n_elems);
        Bucket *b = ht->arData;
        Bucket *end = b + n_used;
        int dict_keys = 1;
        for (; b < end; b++) {
            if (Z_TYPE(b->val) == IS_UNDEF) continue;
            uint32_t kidx;
            if (!b->key || !enc_key_is_schema_eligible(b->key)
                || !enc_str_dict_idx(e, b->key, &kidx)) {
                dict_keys = 0;
                break;
            }
            pos = varint_put(base, pos, kidx);
        }
        if (dict_keys) {
            body->len = pos;
            e->wire_v2 = 1;
            if (UNEXPECTED(ht_shared)) {
                if (n_used != n_elems) {
                    HashTable *dup = zend_array_dup(ht);
                    Bucket *vb = dup->arData;
                    Bucket *vend = vb + dup->nNumUsed;
                    uint32_t wflags = enc_walk_flags(in_rcn_array, shared_arr, dup);
                    for (; vb < vend; vb++) {
                        if (Z_TYPE(vb->val) == IS_UNDEF) continue;
                        encode_value_hot(body, e, &vb->val, wflags);
                        if (UNEXPECTED(e->failed)) break;
                    }
                    enc_release_dup(e, dup);
                    return;
                }
                if (UNEXPECTED(e->depth == 1)) {
                    encode_top_shared_assoc(body, e, ht, in_rcn_array,
                                            shared_arr, false);
                    return;
                }
                HashTable *dup = NULL;
                Bucket *vb = ht->arData;
                uint32_t wflags = enc_walk_flags(in_rcn_array, shared_arr, NULL);
                for (uint32_t i = 0; i < n_used; i++) {
                    if (dup == NULL && Z_TYPE(vb[i].val) >= IS_ARRAY) {
                        dup = zend_array_dup(ht);
                        ZEND_ASSERT(dup->nNumUsed == n_used);
                        vb = dup->arData;
                        wflags = enc_walk_flags(in_rcn_array, shared_arr, dup);
                    }
                    encode_value_hot(body, e, &vb[i].val, wflags);
                    if (UNEXPECTED(e->failed)) break;
                }
                if (dup) enc_release_dup(e, dup);
                return;
            }
            /* Unshared assoc: no dup possible, walk buckets directly. */
            Bucket *vb = ht->arData;
            Bucket *vend = vb + n_used;
            uint32_t wflags = enc_walk_flags(in_rcn_array, shared_arr, NULL);
            for (; vb < vend; vb++) {
                if (Z_TYPE(vb->val) == IS_UNDEF) continue;
                encode_value_hot(body, e, &vb->val, wflags);
                if (UNEXPECTED(e->failed)) break;
            }
            return;
        }
        body->len = save_len;
    }

    emit_tag_and_varint(body, TAG_ASSOC, n_elems);
    if (UNEXPECTED(ht_shared)) {
        if (n_used != n_elems) {
            HashTable *dup = zend_array_dup(ht);
            uint32_t wflags = enc_walk_flags(in_rcn_array, shared_arr, dup);
            Bucket *b = dup->arData;
            Bucket *wend = b + dup->nNumUsed;
            for (; b < wend; b++) {
                if (Z_TYPE(b->val) == IS_UNDEF) continue;
                if (UNEXPECTED(e->failed || PHPSER_HAS_OOM())) {
                    e->failed = 1;
                    break;
                }
                if (b->key) {
                    enc_emit_str_key_ex(body, e, b->key, shared_arr);
                } else {
                    emit_tag_and_varint(body, KEY_LONG, zigzag_encode64((int64_t)(zend_long)b->h));
                }
                encode_value_hot(body, e, &b->val, wflags);
            }
            enc_release_dup(e, dup);
            return;
        }
        if (UNEXPECTED(e->depth == 1)) {
            encode_top_shared_assoc(body, e, ht, in_rcn_array, shared_arr, true);
            return;
        }
        HashTable *dup = NULL;
        Bucket *b = ht->arData;
        uint32_t wflags = enc_walk_flags(in_rcn_array, shared_arr, NULL);
        for (uint32_t i = 0; i < n_used; i++) {
            if (UNEXPECTED(e->failed || (i > 0 && PHPSER_HAS_OOM()))) {
                e->failed = 1;
                break;
            }
            if (b[i].key) {
                enc_emit_str_key_ex(body, e, b[i].key, shared_arr);
            } else {
                emit_tag_and_varint(body, KEY_LONG, zigzag_encode64((int64_t)(zend_long)b[i].h));
            }
            if (dup == NULL && Z_TYPE(b[i].val) >= IS_ARRAY) {
                dup = zend_array_dup(ht);
                ZEND_ASSERT(dup->nNumUsed == n_used);
                b = dup->arData;
                wflags = enc_walk_flags(in_rcn_array, shared_arr, dup);
            }
            encode_value_hot(body, e, &b[i].val, wflags);
        }
        if (dup) enc_release_dup(e, dup);
        return;
    }
    Bucket *b = ht->arData;
    Bucket *wend = b + n_used;
    uint32_t wflags = enc_walk_flags(in_rcn_array, shared_arr, NULL);
    for (; b < wend; b++) {
        if (Z_TYPE(b->val) == IS_UNDEF) continue;
        if (b->key) {
            enc_emit_str_key_ex(body, e, b->key, shared_arr);
        } else {
            emit_tag_and_varint(body, KEY_LONG, zigzag_encode64((int64_t)(zend_long)b->h));
        }
        encode_value_hot(body, e, &b->val, wflags);
        if (UNEXPECTED(e->failed || PHPSER_HAS_OOM())) {
            e->failed = 1;
            break;
        }
    }
}

/* Top-level shared array walkers (depth == 1 && ht_shared). Outlined below
 * encode_hashtable so nested calls keep tight code layout and small frames. */
static zend_never_inline void encode_top_shared_packed(
    enc_buf *body, encode_ctx *e, HashTable *ht,
    bool in_rcn_array, bool shared_arr, bool sparse)
{
    uint32_t n_used = ht->nNumUsed;
    HashTable *dup = NULL;
    zval *wzp = ht->arPacked;
    uint32_t wflags = enc_walk_flags(in_rcn_array, shared_arr, NULL);
    bool top_track = true;
    e->top_ht = ht;
    e->top_dup = NULL;
    for (uint32_t i = 0; i < n_used; i++) {
        if (sparse && Z_TYPE(wzp[i]) == IS_UNDEF) continue;
        if (UNEXPECTED(e->failed || (i > 0 && PHPSER_HAS_OOM()))) {
            e->failed = 1;
            break;
        }
        if (sparse) {
            emit_tag_and_varint(body, KEY_LONG, zigzag_encode64((int64_t)i));
        }
        encode_value_hot(body, e, &wzp[i], wflags);
        if (top_track && UNEXPECTED(e->top_dup != NULL)) {
            dup = e->top_dup;
            e->top_dup = NULL;
            e->top_ht = NULL;
            top_track = false;
            wzp = dup->arPacked;
            wflags = enc_walk_flags(in_rcn_array, shared_arr, dup);
        }
    }
    if (top_track) e->top_ht = NULL;
    if (dup) enc_release_dup(e, dup);
}

static zend_never_inline void encode_top_shared_assoc(
    enc_buf *body, encode_ctx *e, HashTable *ht,
    bool in_rcn_array, bool shared_arr, bool emit_keys)
{
    uint32_t n_used = ht->nNumUsed;
    HashTable *dup = NULL;
    Bucket *b = ht->arData;
    uint32_t wflags = enc_walk_flags(in_rcn_array, shared_arr, NULL);
    bool top_track = true;
    e->top_ht = ht;
    e->top_dup = NULL;
    for (uint32_t i = 0; i < n_used; i++) {
        if (Z_TYPE(b[i].val) == IS_UNDEF) continue;
        if (UNEXPECTED(e->failed || (i > 0 && PHPSER_HAS_OOM()))) {
            e->failed = 1;
            break;
        }
        if (emit_keys) {
            if (b[i].key) {
                enc_emit_str_key_ex(body, e, b[i].key, shared_arr);
            } else {
                emit_tag_and_varint(body, KEY_LONG, zigzag_encode64((int64_t)(zend_long)b[i].h));
            }
        }
        encode_value_hot(body, e, &b[i].val, wflags);
        if (top_track && UNEXPECTED(e->top_dup != NULL)) {
            dup = e->top_dup;
            e->top_dup = NULL;
            e->top_ht = NULL;
            top_track = false;
            ZEND_ASSERT(dup->nNumUsed == n_used);
            b = dup->arData;
            wflags = enc_walk_flags(in_rcn_array, shared_arr, dup);
        }
    }
    if (top_track) e->top_ht = NULL;
    if (dup) enc_release_dup(e, dup);
}

enum { DEFER_UNSERIALIZE, DEFER_WAKEUP };

typedef struct {
    uint8_t kind;
    zend_object *obj;
    zval data;
} deferred_event;

/* Store GC entities as pointer-sized tagged slots using the low 2 bits
 * (ZEND_MM_ALIGNMENT >= 4 on both 32-bit and 64-bit platforms). OBJ/REF/ARR
 * slots are pinned until decode_destroy; ID_TAG_SPECIAL sentinels own nothing. */
typedef uintptr_t id_slot;
#define ID_TAG_MASK       ((uintptr_t)3u)
#define ID_TAG_OBJ        ((uintptr_t)0u)
#define ID_TAG_REF        ((uintptr_t)1u)
#define ID_TAG_ARR        ((uintptr_t)2u)
#define ID_TAG_SPECIAL    ((uintptr_t)3u)
#define ID_SLOT_NULL      ((uintptr_t)3u)
#define ID_SLOT_EMPTY_ARR ((uintptr_t)7u)
#define ID_SLOT_MAKE(ptr, tag) (((uintptr_t)(ptr)) | (tag))
#define ID_SLOT_TAG(s)         ((s) & ID_TAG_MASK)
#define ID_SLOT_PTR(s)         ((void *)((s) & ~ID_TAG_MASK))
ZEND_STATIC_ASSERT(ZEND_MM_ALIGNMENT >= 4, "id_slot requires >= 4-byte alignment");

/* Stack slots that cover small payloads (one DTO, a lone shared literal array)
 * before the id table moves to the heap. */
#define DEC_ID_INLINE_CAP 16
#define DEC_DICT_INLINE_CAP 32
#define DEC_CLASS_INLINE_CAP 16

#define DEC_CS_CNAME_VALID  (1u << 0)
#define DEC_CS_UNINST       (1u << 1)
#define DEC_CS_LEGACY_ONLY  (1u << 2)
#define DEC_CS_SLOTS_FAST   (1u << 3)

typedef struct {
    zend_class_entry *ce;
    uint32_t slot_count;
    uint8_t flags;
    uint8_t allow;     /* 0 unknown, 1 allowed, 2 denied */
    uint8_t hook;      /* HOOK_NONE / HOOK_UNSERIALIZE / HOOK_WAKEUP */
    uint8_t ce_inited; /* 1 once slot_count/flags/hook computed for ce */
} dec_class_slot;

typedef struct {
    zend_class_entry *ce;
    zend_string *key;
    zend_property_info *info;
} dec_property_cache;

typedef struct {
    const uint8_t *buf;
    size_t len;
    size_t pos;
    /* Own strings until decode teardown; consumers take independent references. */
    zend_string **dict;
    zend_string **dict_inline;
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
    /* Unified per-dict-index class cache: class-name validity, allow decision,
     * resolved zend_class_entry (successes only: autoload may resolve misses
     * later), slot count, hook kind, and fast-path eligibility. */
    dec_class_slot *class_slots;
    dec_class_slot *class_slots_inline;
    /* Lowercased class names by dictionary index for lookups that must not
     * autoload. A miss is not cached, so each repeat lookup would otherwise
     * lowercase and hash the whole name again. */
    zend_string **lc_cache;
    /* C-stack recursion guard for container tags that recurse through
     * decode_value. Uncapped, a crafted payload blows the pthread stack at
     * ~100K nested frames. */
    uint32_t depth;
    /* Elements already materialized under sub-linear tags; see
     * PHPSER_SUBLINEAR_MAX_ELEMS. */
    uint32_t sublinear_elems;
    size_t materialize_left;
    size_t compare_left;
    dec_property_cache *property_cache;
    /* Set when a success path dropped a decoded value that can hold graph
     * edges, or (conservatively) ran a legacy Serializable hook, so a pinned
     * entity may no longer be reachable from the result. decode_destroy then releases pins
     * through the GC-root path. Autoloaders, constant-expression evaluation,
     * E_DEPRECATED handlers, and destructors of unrelated garbage never see a
     * graph entity and leave it clear. */
    bool pins_may_orphan;
    /* Set once an object or reference claims an id. Without one the graph is
     * acyclic (a TAG_SHARED_ARRAY array holds no reference), so the returned
     * value needs no GC root. */
    bool ids_may_cycle;
    /* Stack-backed table flags until heap allocation. */
    bool id_table_heap;
    bool dict_heap;
    bool class_slots_heap;
    /* PHP references created or looked up so far; TAG_SHARED_ARRAY rejects a
     * payload that moves it, mirroring the encoder's refs_seen rule. */
    uint32_t refs_seen;
} decode_ctx;

#define MAX_HASH_CHAIN_LENGTH 64
static zend_always_inline int dec_charge_comparison(decode_ctx *d, size_t bytes)
{
    if (UNEXPECTED(bytes > d->compare_left)) return -1;
    d->compare_left -= bytes;
    return 0;
}

static zend_always_inline int dec_strings_equal(
    decode_ctx *d, zend_string *a, zend_string *b)
{
    if (a == b) return 1;
    if (ZSTR_LEN(a) != ZSTR_LEN(b)
        || zend_string_hash_val(a) != zend_string_hash_val(b)) return 0;
    if (ZSTR_LEN(a) > 64
        && UNEXPECTED(dec_charge_comparison(d, ZSTR_LEN(a)) < 0)) return -1;
    return memcmp(ZSTR_VAL(a), ZSTR_VAL(b), ZSTR_LEN(a)) == 0;
}

/* Count full-hash collisions separately from low-bit bucket sharing. Charge
 * long walks and potential byte comparisons before Zend repeats the lookup. */
static zend_always_inline int dec_string_hash_chain_exhausted(
    decode_ctx *d, HashTable *ht, zend_string *key)
{
    if ((ht->nNumOfElements < MAX_HASH_CHAIN_LENGTH && ZSTR_LEN(key) <= 64)
        || ht->nNumOfElements == 0 || HT_IS_PACKED(ht)) return 0;
    zend_ulong h = zend_string_hash_val(key);
    uint32_t idx = HT_HASH(ht, (uint32_t)h | ht->nTableMask);
    uint32_t chain_len = 0, collisions = 0;
    while (idx != HT_INVALID_IDX) {
        Bucket *bucket = HT_HASH_TO_BUCKET(ht, idx);
        if (bucket->h == h && bucket->key != NULL) {
            if (UNEXPECTED(++collisions >= MAX_HASH_CHAIN_LENGTH)) return 1;
            if (ZSTR_LEN(key) > 64 && bucket->key != key
                && ZSTR_LEN(bucket->key) == ZSTR_LEN(key)
                && UNEXPECTED(dec_charge_comparison(d, ZSTR_LEN(key)) < 0)) {
                return 1;
            }
        }
        if (UNEXPECTED(++chain_len == MAX_HASH_CHAIN_LENGTH)) {
            if (dec_charge_comparison(d, chain_len) < 0) return 1;
            chain_len = 0;
        }
        idx = Z_NEXT(bucket->val);
    }
    return 0;
}

/* A payload-declared count sizes one allocation before any element is read,
 * so the per-element PHPSER_HAS_OOM() checks cannot stop it from passing the
 * hard limit. Above DEC_FALLIBLE_MIN_BYTES such presizes are fallible: past
 * memory_limit they fail with the memory_limit error queued. Smaller ones,
 * at most 2 MiB after power-of-two rounding, charge no more than the fresh
 * 2 MiB chunk any small allocation can. */
#ifdef ZEND_HAS_OOM
# define DEC_FALLIBLE_MIN_BYTES ((size_t)1 << 20)
# define DEC_FALLIBLE(n, unit) \
    UNEXPECTED((uint64_t)(n) > DEC_FALLIBLE_MIN_BYTES / (unit))
#endif
#define DEC_MIXED_SLOT_BYTES (sizeof(Bucket) + 2 * sizeof(uint32_t))

enum { DEC_INIT_LAZY, DEC_INIT_PACKED, DEC_INIT_MIXED };

#ifdef ZEND_HAS_OOM
static zend_never_inline zend_array *dec_new_array_fallible(uint32_t n, bool packed) {
    zend_array *arr = zend_new_array(n);
    if (zend_hash_real_finit(arr, packed, true) == FAILURE) {
        zend_array_destroy(arr);
        return NULL;
    }
    return arr;
}
#endif

/* NULL only with a memory_limit error pending. A large DEC_INIT_LAZY table is
 * initialized mixed up front, so its first insert cannot allocate it
 * infallibly. */
static zend_always_inline zend_array *dec_new_array(uint32_t n, int init) {
#ifdef ZEND_HAS_OOM
    if (DEC_FALLIBLE(n, init == DEC_INIT_PACKED
                        ? sizeof(zval) : DEC_MIXED_SLOT_BYTES)) {
        return dec_new_array_fallible(n, init == DEC_INIT_PACKED);
    }
#endif
    zend_array *arr = zend_new_array(n);
    if (init == DEC_INIT_PACKED) {
        zend_hash_real_init_packed(arr);
    } else if (init == DEC_INIT_MIXED) {
        zend_hash_real_init_mixed(arr);
    }
    return arr;
}

static zend_always_inline void *dec_safe_emalloc(size_t n, size_t unit) {
#ifdef ZEND_HAS_OOM
    if (DEC_FALLIBLE(n, unit)) return safe_femalloc(n, unit, 0, true);
#endif
    return safe_emalloc(n, unit, 0);
}

static zend_always_inline void *dec_ecalloc(size_t n, size_t unit) {
#ifdef ZEND_HAS_OOM
    if (DEC_FALLIBLE(n, unit)) return fecalloc(n, unit, true);
#endif
    return ecalloc(n, unit);
}

/* A payload buffer outside the Zend heap (APCu shared memory, a C caller's
 * malloc or mmap) is not charged to memory_limit, so a length it declares can
 * pass the hard limit in one copy. NULL only with a memory_limit error pending. */
static zend_always_inline zend_string *dec_string_init(const char *s, size_t len) {
#ifdef ZEND_HAS_OOM
    if (DEC_FALLIBLE(len, 1)) return zend_string_finit(s, len, 0, true);
#endif
    return zend_string_init(s, len, 0);
}

static zend_always_inline zend_string *dec_string_init_existing_interned(
    const char *s, size_t len)
{
#ifdef ZEND_HAS_OOM
    if (DEC_FALLIBLE(len, 1)) return zend_string_finit_existing_interned(s, len, false, true);
#endif
    return zend_string_init_existing_interned(s, len, 0);
}

static zend_never_inline void dec_id_table_grow(decode_ctx *d) {
    uint32_t cap = d->id_table_cap <= 1024 ? d->id_table_cap * 4 : d->id_table_cap * 2;
    if (d->id_table_heap) {
        d->id_table = erealloc(d->id_table, cap * sizeof(id_slot));
    } else {
        id_slot *heap = emalloc(cap * sizeof(id_slot));
        memcpy(heap, d->id_table, d->id_table_len * sizeof(id_slot));
        d->id_table = heap;
        d->id_table_heap = true;
    }
    d->id_table_cap = cap;
}

static zend_always_inline void dec_register_obj(decode_ctx *d, zend_object *obj) {
    if (UNEXPECTED(d->id_table_len == d->id_table_cap)) {
        dec_id_table_grow(d);
    }
    ZEND_ASSERT(((uintptr_t)obj & ID_TAG_MASK) == 0);
    GC_ADDREF(obj);
    d->id_table[d->id_table_len++] = ID_SLOT_MAKE(obj, ID_TAG_OBJ);
    d->ids_may_cycle = true;
}

/* Claim ids in the encoder's encounter order, before decoding children
 * (TAG_SHARED_ARRAY alone registers after them). Every id-claiming tag must
 * register here; TAG_NULL claims none. Pin every entity until decode_destroy:
 * overwriting a duplicate key can drop the graph's last reference before a
 * later TAG_REF uses it. */
static void dec_register(decode_ctx *d, zval *z) {
    if (UNEXPECTED(d->id_table_len == d->id_table_cap)) {
        dec_id_table_grow(d);
    }
    id_slot slot;
    if (Z_TYPE_P(z) == IS_OBJECT) {
        zend_object *obj = Z_OBJ_P(z);
        ZEND_ASSERT(((uintptr_t)obj & ID_TAG_MASK) == 0);
        /* Signed frames also need pins: a valid HMAC does not prove key uniqueness. */
        GC_ADDREF(obj);
        slot = ID_SLOT_MAKE(obj, ID_TAG_OBJ);
        d->ids_may_cycle = true;
    } else if (Z_TYPE_P(z) == IS_REFERENCE) {
        zend_reference *ref = Z_REF_P(z);
        ZEND_ASSERT(((uintptr_t)ref & ID_TAG_MASK) == 0);
        GC_ADDREF(ref);
        slot = ID_SLOT_MAKE(ref, ID_TAG_REF);
        d->ids_may_cycle = true;
    } else if (Z_TYPE_P(z) == IS_ARRAY) {
        /* Only TAG_SHARED_ARRAY registers arrays, after the array is complete. */
        if (Z_REFCOUNTED_P(z)) {
            zend_array *arr = Z_ARR_P(z);
            ZEND_ASSERT(((uintptr_t)arr & ID_TAG_MASK) == 0);
            GC_ADDREF(arr);
            slot = ID_SLOT_MAKE(arr, ID_TAG_ARR);
        } else {
            slot = ID_SLOT_EMPTY_ARR;
        }
    } else {
        /* The encoder claimed an id here; register a NULL slot to keep ids
         * aligned. Back-refs to it yield NULL. */
        slot = ID_SLOT_NULL;
    }
    d->id_table[d->id_table_len++] = slot;
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

enum { HOOK_NONE, HOOK_UNSERIALIZE, HOOK_WAKEUP };

static zend_always_inline uint8_t dec_prepare_hook(zend_object *obj) {
    zend_class_entry *ce = obj->ce;
    uint8_t hook = HOOK_NONE;
    if (ce->__unserialize != NULL) {
        hook = HOOK_UNSERIALIZE;
    } else if (zend_hash_find_known_hash(&ce->function_table,
            ZSTR_KNOWN(ZEND_STR_WAKEUP)) != NULL) {
        hook = HOOK_WAKEUP;
    }
    if (hook != HOOK_NONE && ce->destructor != NULL) {
        /* Suppress cleanup even if decoding fails before the hook is queued. */
        GC_ADD_FLAGS(obj, IS_OBJ_DESTRUCTOR_CALLED);
    }
    return hook;
}

/* object_init_ex must not run on these: NOT_SERIALIZABLE yields a corrupt
 * instance; UNINSTANTIABLE throws past the decoder's return-NULL contract. */
static zend_always_inline int dec_ce_uninstantiable(zend_class_entry *ce) {
    return (ce->ce_flags & (ZEND_ACC_NOT_SERIALIZABLE | ZEND_ACC_UNINSTANTIABLE)) != 0;
}

static zend_result dec_unserialize_legacy(decode_ctx *d, zval *out,
    zend_class_entry *ce, const unsigned char *payload, size_t len)
{
    php_unserialize_data_t saved_unserialize = BG(unserialize).data;
    unsigned saved_unserialize_level = BG(unserialize).level;
    php_serialize_data_t saved_serialize = BG(serialize).data;
    unsigned saved_serialize_level = BG(serialize).level;
    unsigned saved_lock = BG(serialize_lock);

    /* SPL opens its native decoder through BG(unserialize), not the hook's
     * var_hash argument. Give each opaque payload its own reference table,
     * including calls made from a native serialization magic method. */
    BG(unserialize).data = NULL;
    BG(unserialize).level = 0;
    BG(serialize).data = NULL;
    BG(serialize).level = 0;
    BG(serialize_lock) = 0;
    php_unserialize_data_t var_hash;
    PHP_VAR_UNSERIALIZE_INIT(var_hash);
    php_var_unserialize_set_allowed_classes(var_hash, d->allowed_set);
    zend_long max_depth = php_var_unserialize_get_max_depth(var_hash);
    if (max_depth == 0 || max_depth > MAX_DEPTH) max_depth = MAX_DEPTH;
    php_var_unserialize_set_max_depth(var_hash, max_depth);
    php_var_unserialize_set_cur_depth(var_hash, d->depth);

    zend_result result = FAILURE;
    bool bailed_out = false;
    zend_try {
        result = ce->unserialize(out, ce, payload, len,
                                 (zend_unserialize_data *)&var_hash);
        PHP_VAR_UNSERIALIZE_DESTROY(var_hash);
    } zend_catch {
        bailed_out = true;
    } zend_end_try();
    BG(unserialize).data = saved_unserialize;
    BG(unserialize).level = saved_unserialize_level;
    BG(serialize).data = saved_serialize;
    BG(serialize).level = saved_serialize_level;
    BG(serialize_lock) = saved_lock;
    if (bailed_out) zend_bailout();
    return EG(exception) ? FAILURE : result;
}

static zend_never_inline dec_class_slot *dec_class_slots_init(decode_ctx *d) {
    if (d->dict_len <= DEC_CLASS_INLINE_CAP) {
        memset(d->class_slots_inline, 0, (size_t)d->dict_len * sizeof(dec_class_slot));
        d->class_slots = d->class_slots_inline;
        return d->class_slots;
    }
    d->class_slots = dec_ecalloc(d->dict_len, sizeof(dec_class_slot));
    if (EXPECTED(d->class_slots != NULL)) {
        d->class_slots_heap = true;
    }
    return d->class_slots;
}

static zend_always_inline dec_class_slot *dec_class_slot_get(decode_ctx *d, uint64_t idx) {
    dec_class_slot *slots = d->class_slots;
    if (UNEXPECTED(!slots)) {
        slots = dec_class_slots_init(d);
        if (UNEXPECTED(!slots)) return NULL;
    }
    return &slots[idx];
}

static zend_never_inline void dec_class_slot_populate(dec_class_slot *cs, zend_class_entry *ce) {
    cs->ce = ce;
    cs->slot_count = ce_table_slot_count(ce);
    uint8_t f = cs->flags;
    if (dec_ce_uninstantiable(ce)) f |= DEC_CS_UNINST;
    if (ce->serialize != NULL && ce->__unserialize == NULL) f |= DEC_CS_LEGACY_ONLY;
    uint8_t hook = HOOK_NONE;
    if (ce->__unserialize != NULL) {
        hook = HOOK_UNSERIALIZE;
    } else if (zend_hash_find_known_hash(&ce->function_table,
            ZSTR_KNOWN(ZEND_STR_WAKEUP)) != NULL) {
        hook = HOOK_WAKEUP;
    }
    cs->hook = hook;
    if (ce->create_object == NULL
        && !(f & (DEC_CS_UNINST | DEC_CS_LEGACY_ONLY))
        && ce->serialize == NULL
        && ce->destructor == NULL
        && hook == HOOK_NONE
        && cs->slot_count == (uint32_t)ce->default_properties_count
#if PHP_VERSION_ID >= 80400
        && ce->num_hooked_props == 0
#endif
    ) {
        f |= DEC_CS_SLOTS_FAST;
    }
    cs->flags = f;
    cs->ce_inited = 1;
}

/* Callers must bound class_idx via dec_get_zstr first. Cache only successes:
 * autoload may make a missing class available later. */
static inline zend_class_entry *dec_class_resolve_ex(
    decode_ctx *d, uint64_t class_idx, zend_string *class_name, int autoload)
{
    ZEND_ASSERT(class_idx < d->dict_len);
    dec_class_slot *cs = dec_class_slot_get(d, class_idx);
    if (UNEXPECTED(!cs)) return NULL;
    if (EXPECTED(cs->ce != NULL)) {
        return cs->ce;
    }
    zend_string *lc_name = NULL;
    if (!autoload) {
        if (UNEXPECTED(!d->lc_cache)) {
            d->lc_cache = dec_ecalloc(d->dict_len, sizeof(zend_string *));
            if (UNEXPECTED(!d->lc_cache)) return NULL;
        }
        lc_name = d->lc_cache[class_idx];
        if (!lc_name) {
            lc_name = d->lc_cache[class_idx] = zend_string_tolower(class_name);
        }
    }
    /* Autoloaders that call native unserialize() must get their own var_hash,
     * as native O: lookups arrange. */
    if (autoload) BG(serialize_lock)++;
    zend_class_entry *ce = zend_lookup_class_ex(class_name, lc_name,
                                                autoload ? 0 : ZEND_FETCH_CLASS_NO_AUTOLOAD);
    if (autoload) BG(serialize_lock)--;
    if (ce) {
        dec_class_slot_populate(cs, ce);
    }
    return ce;
}
static inline zend_class_entry *dec_class_resolve(
    decode_ctx *d, uint64_t class_idx, zend_string *class_name)
{
    return dec_class_resolve_ex(d, class_idx, class_name, /* autoload */ 1);
}

/* Object tags fall back to unserialize_callback_func after autoload, like
 * native O: and C:. Enums do not, like native E:. Callers check EG(exception). */
static zend_class_entry *dec_class_resolve_obj(
    decode_ctx *d, uint64_t class_idx, zend_string *class_name)
{
    zend_class_entry *ce = dec_class_resolve(d, class_idx, class_name);
    if (ce || EG(exception)) {
        return ce;
    }
    zval user_func, retval, arg;
/* API 20260924 (8.6.0RC2) is the first API number with the zend_string
 * global; 8.6-dev between 2026-07-19 and RC2 reports 20250926 and takes the
 * char * branch wrongly. */
#if PHP_API_VERSION > 20250926
    zend_string *func = PG(unserialize_callback_func);
    if (func == NULL || ZSTR_LEN(func) == 0) {
        return NULL;
    }
    /* The callback may change the INI value and release this string. */
    ZVAL_STR(&user_func, zend_string_dup(func, false));
#else
    const char *func = PG(unserialize_callback_func);
    if (func == NULL || func[0] == '\0') {
        return NULL;
    }
    ZVAL_STRING(&user_func, func);
#endif
    ZVAL_STR(&arg, class_name);
    BG(serialize_lock)++;
    call_user_function(NULL, NULL, &user_func, &retval, 1, &arg);
    BG(serialize_lock)--;
    zval_ptr_dtor(&retval);

    if (!EG(exception)) {
        ce = dec_class_resolve(d, class_idx, class_name);
        if (!ce && !EG(exception)) {
            php_error_docref(NULL, E_WARNING,
                "Function " PHPSER_ZSTR_FMT "() hasn't defined the class it was called for",
                PHPSER_ZSTR_ARG(Z_STR(user_func)));
        }
    }
    zval_ptr_dtor(&user_func);
    return ce;
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
    dec_class_slot *cs = dec_class_slot_get(d, class_idx);
    if (UNEXPECTED(!cs)) return 0;
    if (EXPECTED(cs->allow != 0)) return cs->allow == 1;
    zend_string *lc = zend_string_tolower(class_name);
    int ok = zend_hash_exists(d->allowed_set, lc);
    zend_string_release(lc);
    cs->allow = ok ? 1 : 2;
    return ok;
}

/* Call before a success path destroys a value it no longer stores. Strings and
 * non-refcounted values hold no GC entity, and enum cases are owned by their
 * class constants with no edge into the graph, so dropping them cannot orphan a
 * pin. */
static zend_always_inline void dec_note_drop(decode_ctx *d, const zval *zv) {
    if (!Z_REFCOUNTED_P(zv) || Z_TYPE_P(zv) == IS_STRING) return;
    if (Z_TYPE_P(zv) == IS_OBJECT
        && (Z_OBJCE_P(zv)->ce_flags & ZEND_ACC_ENUM)) {
        return;
    }
    d->pins_may_orphan = true;
}

/* A declared slot's first write replaces the default object_init_ex copied
 * from the class table. The class keeps its own reference to that value, which
 * predates the decode and so cannot reach decoded entities. */
static zend_always_inline void dec_note_slot_drop(decode_ctx *d, zend_object *obj,
                                                  uint32_t num, const zval *slot) {
    if (!Z_REFCOUNTED_P(slot) || Z_TYPE_P(slot) == IS_STRING) return;
    if (Z_TYPE_P(slot) == IS_OBJECT
        && (Z_OBJCE_P(slot)->ce_flags & ZEND_ACC_ENUM)) {
        return;
    }
    if (EXPECTED(num < (uint32_t)obj->ce->default_properties_count)) {
        const zval *def = &CE_DEFAULT_PROPERTIES_TABLE(obj->ce)[num];
        if (Z_REFCOUNTED_P(def) && Z_COUNTED_P(def) == Z_COUNTED_P(slot)) return;
    }
    d->pins_may_orphan = true;
}

/* Fast-accept scalar mask hits and single-class exact CE matches without
 * calling zend_verify_prop_assignable_by_ref. */
static zend_always_inline bool dec_prop_type_fast_accept(zend_property_info *info, zval *tmp) {
    if (Z_ISREF_P(tmp)) return false;
    uint8_t t = Z_TYPE_P(tmp);
    if (EXPECTED(t != IS_OBJECT)) {
        return ZEND_TYPE_CONTAINS_CODE(info->type, t);
    }
    if (ZEND_TYPE_HAS_NAME(info->type)) {
        zend_string *tname = ZEND_TYPE_NAME(info->type);
        if (ZSTR_HAS_CE_CACHE(tname) && ZSTR_GET_CE_CACHE(tname) == Z_OBJCE_P(tmp)) {
            return true;
        }
    }
    return false;
}

/* Write a decoded value into a declared property slot (typed or untyped).
 * Takes ownership of *tmp: on success it's moved into the slot; on
 * type-mismatch it's dtor'd. Returns 0/-1. slot_is_default promises the slot
 * holds no value written by this decode (usually its object_init_ex default;
 * exception objects carry file/line/trace), so replacing it needs no drop note. */
static zend_always_inline int dec_install_slot_impl(decode_ctx *d, zend_object *obj,
                                                    zend_property_info *info, zval *tmp,
                                                    bool slot_is_default) {
    zval *slot = OBJ_PROP(obj, info->offset);
    if (ZEND_TYPE_IS_SET(info->type)) {
        if (UNEXPECTED(!dec_prop_type_fast_accept(info, tmp))
            && (UNEXPECTED(PHPSER_HAS_OOM())
                || !zend_verify_prop_assignable_by_ref(info, tmp, /*strict*/ 1))) {
            i_zval_ptr_dtor(tmp);
            return -1;
        }
        if (Z_ISREF_P(slot)) {
            ZEND_REF_DEL_TYPE_SOURCE(Z_REF_P(slot), info);
        }
        if (!slot_is_default) {
            dec_note_slot_drop(d, obj, OBJ_PROP_TO_NUM(info->offset), slot);
        }
        i_zval_ptr_dtor(slot);
        ZVAL_COPY_VALUE(slot, tmp);
        if (Z_ISREF_P(slot)) {
            ZEND_REF_ADD_TYPE_SOURCE(Z_REF_P(slot), info);
        }
    } else {
        if (!slot_is_default) {
            dec_note_slot_drop(d, obj, OBJ_PROP_TO_NUM(info->offset), slot);
        }
        i_zval_ptr_dtor(slot);
        ZVAL_COPY_VALUE(slot, tmp);
    }
    return 0;
}

/* Separate copies keep the per-slot OBJECT_SLOTS path free of the decode_ctx
 * argument and the drop-note branch; it runs once per declared property.
 * Out of line: inlined, the copies enlarge decode_value_inner and cost the
 * TAG_ASSOC path extra instructions. */
static zend_never_inline int dec_install_default_slot(
    zend_object *obj, zend_property_info *info, zval *tmp)
{
    return dec_install_slot_impl(NULL, obj, info, tmp, /* slot_is_default */ true);
}

static zend_never_inline int dec_install_declared_slot(
    decode_ctx *d, zend_object *obj, zend_property_info *info, zval *tmp)
{
    return dec_install_slot_impl(d, obj, info, tmp, /* slot_is_default */ false);
}

/* Install one decoded value as a property on `obj`. Mirrors TAG_OBJECT's
 * IS_INDIRECT-vs-dynamic dispatch with typed-slot checking. Used by both
 * TAG_OBJECT and TAG_OBJECT_MAGIC's __unserialize-missing fallback.
 * Takes ownership of *tmp. Returns 0/-1. */
static int dec_install_prop_resolved(decode_ctx *d, zend_object *obj, HashTable *obj_props,
    zend_string *key, zend_property_info *declared, zval *tmp) {
    /* Reserve the original class-name marker: a wire overwrite could resurrect
     * another class after reserialization. The encoder omits this marker; rejecting
     * the overwrite is stricter than native. */
    if (UNEXPECTED(obj->ce == PHP_IC_ENTRY)
        && zend_string_equals_literal(key, MAGIC_MEMBER)) {
        dec_note_drop(d, tmp);
        zval_ptr_dtor(tmp);
        return 0;
    }
#if PHP_VERSION_ID >= 80400
    if (UNEXPECTED(declared != NULL && (declared->flags & ZEND_ACC_VIRTUAL))) {
        zval_ptr_dtor(tmp);
        return -1;
    }
#endif
    if (declared != NULL) key = declared->name;
    if (UNEXPECTED(dec_string_hash_chain_exhausted(d, obj_props, key))) {
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
                if (UNEXPECTED(PHPSER_HAS_OOM())
                    || !zend_verify_prop_assignable_by_ref(
                        info, tmp, /*strict*/ 1)) {
                    zval_ptr_dtor(tmp);
                    return -1;
                }
                if (Z_ISREF_P(slot)) {
                    ZEND_REF_DEL_TYPE_SOURCE(Z_REF_P(slot), info);
                }
            }
            /* Standard property tables point IS_INDIRECT entries into
             * properties_table, as zend_get_typed_property_info_for_slot also
             * assumes. */
            dec_note_slot_drop(d, obj,
                (uint32_t)(slot - obj->properties_table), slot);
            i_zval_ptr_dtor(slot);
            ZVAL_COPY_VALUE(slot, tmp);
            if (info != NULL && Z_ISREF_P(slot)) {
                ZEND_REF_ADD_TYPE_SOURCE(Z_REF_P(slot), info);
            }
        } else {
            dec_note_drop(d, existing);
            i_zval_ptr_dtor(existing);
            ZVAL_COPY_VALUE(existing, tmp);
        }
    } else {
        /* Both diagnostics below would compete with a pending memory_limit
         * error, which is the request's only report. */
        if (UNEXPECTED(!(obj->ce->ce_flags & ZEND_ACC_ALLOW_DYNAMIC_PROPERTIES)
                       && PHPSER_HAS_OOM())) {
            zval_ptr_dtor(tmp);
            return -1;
        }
        if (UNEXPECTED(obj->ce->ce_flags & ZEND_ACC_NO_DYNAMIC_PROPERTIES)) {
            zend_throw_error(NULL, "Cannot create dynamic property " PHPSER_ZSTR_FMT "::$%s",
                PHPSER_ZSTR_ARG(obj->ce->name),
                zend_get_unmangled_property_name(key));
            zval_ptr_dtor(tmp);
            return -1;
        }
        if (!(obj->ce->ce_flags & ZEND_ACC_ALLOW_DYNAMIC_PROPERTIES)) {
            zend_error(E_DEPRECATED, "Creation of dynamic property " PHPSER_ZSTR_FMT "::$%s is deprecated",
                PHPSER_ZSTR_ARG(obj->ce->name),
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

/* Plain keys and visibility changes address the current declaration, as in native
 * unserialize. A private key belonging to another class requires fallback. */
static zend_always_inline int dec_prop_info_for_key(
    decode_ctx *d, zend_class_entry *ce, zend_string *key, zend_property_info **out)
{
    *out = NULL;
    if (zend_hash_num_elements(&ce->properties_info) == 0) return 0;
    bool long_key = ZSTR_LEN(key) > 64;
    if (UNEXPECTED(long_key)) {
        if (d->property_cache != NULL && d->property_cache->ce == ce
            && d->property_cache->key == key) {
            *out = d->property_cache->info;
            return 0;
        }
        if (dec_charge_comparison(d, ZSTR_LEN(key)) < 0) return -1;
    }
    zend_property_info *info;
    if (EXPECTED(ZSTR_LEN(key) == 0 || ZSTR_VAL(key)[0] != '\0')) {
        if (UNEXPECTED(dec_string_hash_chain_exhausted(d, &ce->properties_info, key))) {
            return -1;
        }
        info = zend_hash_find_ptr(&ce->properties_info, key);
    } else {
        const char *cls_name, *prop_name;
        size_t prop_len;
        if (zend_unmangle_property_name_ex(key, &cls_name, &prop_name,
                                           &prop_len) != SUCCESS) {
            return -1;
        }
        if (UNEXPECTED(long_key)) {
            zend_string *name = zend_string_init(prop_name, prop_len, 0);
            if (dec_string_hash_chain_exhausted(d, &ce->properties_info, name)) {
                zend_string_release(name);
                return -1;
            }
            info = zend_hash_find_ptr(&ce->properties_info, name);
            zend_string_release(name);
        } else {
            info = zend_hash_str_find_ptr(&ce->properties_info, prop_name, prop_len);
        }
        if (!info) goto resolved;
        if (strcmp(cls_name, "*") != 0
            && strcasecmp(cls_name, ZSTR_VAL(ce->name)) != 0) {
            if (!zend_string_equals(info->name, key)) info = NULL;
#if PHP_VERSION_ID >= 80400
            /* An inherited private virtual property has no stored slot. Native
             * preserves its parent's mangled wire key as a dynamic property. */
            if (info != NULL && (info->flags & ZEND_ACC_VIRTUAL)) info = NULL;
#endif
        }
    }
resolved:
    if (info != NULL && UNEXPECTED(info->flags & ZEND_ACC_STATIC)) info = NULL;
    if (UNEXPECTED(long_key)) {
        if (d->property_cache == NULL) {
            d->property_cache = emalloc(sizeof(dec_property_cache));
        } else {
            zend_string_release(d->property_cache->key);
        }
        d->property_cache->ce = ce;
        /* MAGIC data keys can die before the next lookup. */
        d->property_cache->key = zend_string_copy(key);
        d->property_cache->info = info;
    }
    *out = info;
    return 0;
}

static int dec_install_prop(decode_ctx *d, zend_object *obj, HashTable *obj_props,
    zend_string *key, zval *tmp) {
    zend_property_info *info;
    if (dec_prop_info_for_key(d, obj->ce, key, &info) < 0) {
        zval_ptr_dtor(tmp);
        return -1;
    }
    return dec_install_prop_resolved(d, obj, obj_props, key, info, tmp);
}

/* Reject virtual or forbidden dynamic properties before their values can
 * autoload classes. dec_install_prop also guards its other callers. */
static zend_always_inline int dec_prop_forbidden(
    zend_object *obj, HashTable *obj_props, zend_string *key,
    zend_property_info *info) {
#if PHP_VERSION_ID >= 80400
    if (UNEXPECTED(info != NULL && (info->flags & ZEND_ACC_VIRTUAL))) return 1;
#endif
    if (EXPECTED(!(obj->ce->ce_flags & ZEND_ACC_NO_DYNAMIC_PROPERTIES))) {
        return 0;
    }
    /* Declared slot (or an already-present key) is not a new dynamic prop. */
    if (info != NULL) return 0;
    if (obj_props != NULL && zend_hash_find(obj_props, key) != NULL) {
        return 0;
    }
    if (EXPECTED(!PHPSER_HAS_OOM())) {
        zend_throw_error(NULL, "Cannot create dynamic property " PHPSER_ZSTR_FMT "::$%s",
            PHPSER_ZSTR_ARG(obj->ce->name), zend_get_unmangled_property_name(key));
    }
    return 1;
}

/* Without __unserialize, apply data as properties, including to incomplete classes.
 * Integer keys become string-named dynamic properties. */
static int dec_apply_data_as_props(decode_ctx *d, zend_object *obj, HashTable *data_ht) {
    HashTable *obj_props = zend_std_get_properties(obj);
    uint32_t count = zend_hash_num_elements(data_ht);
    uint64_t total = (uint64_t)count + zend_hash_num_elements(obj_props);
    if (total > HT_MAX_SIZE) return -1;
    uint64_t bytes = phpser_mixed_array_bytes((uint32_t)total)
        + (uint64_t)count * 48;
    if (bytes > d->materialize_left) return -1;
    d->materialize_left -= (size_t)bytes;
    zend_string *key;
    zend_ulong h;
    zval *val;
    ZEND_HASH_FOREACH_KEY_VAL(data_ht, h, key, val) {
        if (UNEXPECTED(PHPSER_HAS_OOM())) return -1;
        zval tmp;
        ZVAL_COPY(&tmp, val);  /* addref for the new owner */
        if (key) {
            /* A typed-slot mismatch leaves a pending TypeError; fail the decode
             * instead of installing more props and queueing __wakeup under it. */
            if (dec_install_prop(d, obj, obj_props, key, &tmp) < 0) return -1;
        } else {
            /* Int keys become string-named dynamic properties ("0", "1"), as
             * in PHP. */
            zend_string *str_key = zend_long_to_str((zend_long)h);
            int rc = dec_install_prop(d, obj, obj_props, str_key, &tmp);
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
    dec_class_slot *cs = dec_class_slot_get(d, idx);
    if (UNEXPECTED(!cs)) return NULL;
    if (EXPECTED(cs->flags & DEC_CS_CNAME_VALID)) return name;
    if (ZSTR_LEN(name) == 0 || ZSTR_VAL(name)[0] == '\\'
        || !zend_is_valid_class_name(name)) {
        return NULL;
    }
    cs->flags |= DEC_CS_CNAME_VALID;
    return name;
}

static int decode_value(decode_ctx *d, zval *out);
static zend_string **dec_read_schema_keys(decode_ctx *d, uint64_t nkeys,
                                          zend_string **stack_buf, uint32_t stack_cap);
/* Returns 1 for unique, 0 for duplicates, -1 when the collision budget is
 * exhausted or the key set would pass memory_limit. */
static int dec_schema_keys_are_unique(decode_ctx *d, zend_string **keys, uint64_t nkeys);
static zend_never_inline int dec_decode_table(decode_ctx *d, zval *out);
static zend_never_inline int dec_decode_rowset(decode_ctx *d, zval *out);
static zend_never_inline int dec_decode_shared_array(decode_ctx *d, zval *out);
static zend_never_inline int dec_decode_packed_longs(decode_ctx *d, zval *out);
static zend_never_inline int dec_decode_packed_doubles(decode_ctx *d, zval *out);
static zend_never_inline int dec_decode_packed_strings(decode_ctx *d, zval *out);
static zend_never_inline int dec_decode_packed_delta(decode_ctx *d, zval *out);
static zend_never_inline int dec_decode_object_slots(decode_ctx *d, zval *out);
static zend_never_inline int dec_decode_assoc_dict(decode_ctx *d, zval *out);

/* Eagerly materialize every dict slot with a precomputed hash: one less branch
 * in the per-string hot path, and direct inserts skip hashing. */
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
    /* Scalars and first-occurrence inline strings carry no dict; skipping the
     * table spares a small decode an allocation and a free. dec_get_zstr
     * bounds every index by dict_len, so dict stays NULL. */
    if (n == 0) return 0;
    if (n <= DEC_DICT_INLINE_CAP) {
        d->dict = d->dict_inline;
    } else {
        d->dict = dec_safe_emalloc((size_t)n, sizeof(zend_string *));
        if (UNEXPECTED(!d->dict)) return -1;
        d->dict_heap = true;
    }
    const uint8_t *buf = d->buf;
    size_t len = d->len, pos = d->pos;
    uint32_t count = (uint32_t)n;
    for (uint32_t i = 0; i < count; i++) {
        uint64_t slen;
        if (UNEXPECTED(PHPSER_HAS_OOM())) {
            d->dict_len = i;
            return -1;
        }
        if (varint_read_u64(buf, len, &pos, &slen) < 0
            || slen > UINT32_MAX || slen > len - pos) {
            d->dict_len = i;
            return -1;
        }
        /* Short dict strings probe the engine intern table (common keys/class
         * names); longer strings skip the intern lookup and compute hash once. */
        zend_string *zs;
        if (EXPECTED(slen <= 128)) {
            zs = dec_string_init_existing_interned((const char *)(buf + pos), (size_t)slen);
        } else {
            zs = dec_string_init((const char *)(buf + pos), (size_t)slen);
            if (EXPECTED(zs != NULL)) {
                (void)zend_string_hash_val(zs);
            }
        }
        if (UNEXPECTED(!zs)) {
            d->dict_len = i;
            return -1;
        }
        d->dict[i] = zs;
        pos += (size_t)slen;
    }
    d->dict_len = count;
    d->pos = pos;
    return 0;
}

/* result_live: the decoded value is being returned and every pinned entity is
 * still reachable from it (no drop, no user code). A live entity cannot be
 * cyclic garbage, and phpser_decode_buf_opts buffers the returned value as a
 * root, which reaches every such entity, so the pin release skips
 * gc_possible_root. Otherwise each
 * surviving entity must be offered as a root: it may now be orphaned in a
 * cycle whose earlier root a mid-decode GC run already discarded. */
static void decode_destroy(decode_ctx *d, bool result_live) {
    if (d->property_cache != NULL) {
        zend_string_release(d->property_cache->key);
        efree(d->property_cache);
    }
    if (d->dict) {
        for (uint32_t i = 0; i < d->dict_len; i++) {
            zend_string_release(d->dict[i]);
        }
        if (d->dict_heap) efree(d->dict);
    }
    if (d->id_table_len) {
        /* Release the refcount we took at registration. NULL/EMPTY_ARR slots
         * own nothing; OBJ/REF/ARR release via the type-specific macro on the
         * cold path so destructors and ref-table teardown fire correctly. */
        if (result_live) {
            /* Reachability from the result implies a holder besides the pin.
             * If a missed drop site leaves the pin as the sole holder, the
             * entity gets a full release; a missed drop still held by an
             * orphaned cycle keeps refcount > 1 and leaks until shutdown. */
            for (uint32_t i = 0; i < d->id_table_len; i++) {
                id_slot raw = d->id_table[i];
                if (UNEXPECTED(ID_SLOT_TAG(raw) == ID_TAG_SPECIAL)) continue;
                zend_refcounted *rc = (zend_refcounted *)ID_SLOT_PTR(raw);
                if (EXPECTED(GC_REFCOUNT(rc) > 1)) {
                    GC_DELREF(rc);
                    continue;
                }
                /* Release builds turn ZEND_ASSERT into ZEND_ASSUME, which
                 * would delete this fallback; only debug builds trip. */
#if ZEND_DEBUG
                ZEND_ASSERT(0 && "pin was the last holder; missed dec_note_drop");
#endif
                if (ID_SLOT_TAG(raw) == ID_TAG_OBJ) {
                    OBJ_RELEASE((zend_object *)rc);
                } else {
                    GC_DTOR(rc);
                }
            }
        } else {
            for (uint32_t i = 0; i < d->id_table_len; i++) {
                id_slot raw = d->id_table[i];
                switch (ID_SLOT_TAG(raw)) {
                    case ID_TAG_OBJ:
                        OBJ_RELEASE((zend_object *)ID_SLOT_PTR(raw));
                        break;
                    case ID_TAG_REF:
                        GC_DTOR((zend_reference *)ID_SLOT_PTR(raw));
                        break;
                    case ID_TAG_ARR:
                        GC_DTOR((zend_array *)ID_SLOT_PTR(raw));
                        break;
                    default:
                        break;
                }
            }
        }
    }
    if (d->id_table_heap) efree(d->id_table);
    if (d->deferred) {
        for (uint32_t i = 0; i < d->deferred_len; i++) {
            OBJ_RELEASE(d->deferred[i].obj);
            if (d->deferred[i].kind == DEFER_UNSERIALIZE) {
                zval_ptr_dtor(&d->deferred[i].data);
            }
        }
        efree(d->deferred);
    }
    if (d->class_slots_heap) efree(d->class_slots);
    if (d->lc_cache) {
        for (uint32_t i = 0; i < d->dict_len; i++) {
            if (d->lc_cache[i]) zend_string_release(d->lc_cache[i]);
        }
        efree(d->lc_cache);
    }
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
        zend_string *zs = dec_string_init((const char *)d->buf + d->pos, (size_t)slen);
        if (UNEXPECTED(!zs)) return -1;
        out_key->kind = KV_OWNED_STR;
        out_key->str = zs;
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

/* Tags whose decoded value is always an array: the only legal TAG_SHARED_ARRAY
 * payloads. */
static zend_always_inline int dec_is_array_tag(uint8_t tag) {
    switch (tag) {
        case TAG_ASSOC:
        case TAG_PACKED_MIXED:
        case TAG_PACKED_LONGS:
        case TAG_PACKED_DOUBLES:
        case TAG_PACKED_STRINGS:
        case TAG_ASSOC_DICT:
        case TAG_ROWSET:
        case TAG_TABLE:
        case TAG_PACKED_DELTA:
        case TAG_PACKED_AFFINE:
            return 1;
        default:
            return 0;
    }
}

/* Outlined so decode_scalar_tag stays a leaf with zero callee-saved spills on
 * non-allocating scalars (NULL/FALSE/TRUE/LONG/DOUBLE/STR_DICT). */
static zend_never_inline int dec_decode_str_inline(decode_ctx *d, zval *out) {
    if (UNEXPECTED(PHPSER_HAS_OOM())) return -1;
    uint64_t slen;
    if (varint_read_u64(d->buf, d->len, &d->pos, &slen) < 0) return -1;
    if (slen > UINT32_MAX || slen > d->len - d->pos) return -1;
    zend_string *zs = dec_string_init((const char *)d->buf + d->pos, (size_t)slen);
    if (UNEXPECTED(!zs)) return -1;
    d->pos += (size_t)slen;
    ZVAL_STR(out, zs);
    return 0;
}

/* Decode a tag whose leading byte was already consumed. Caller must only pass
 * scalar tags; returns -1 on truncation. */
static zend_always_inline int decode_scalar_tag(decode_ctx *d, zval *out, uint8_t tag) {
    ZEND_STATIC_ASSERT(IS_NULL == 1 && IS_FALSE == 2 && IS_TRUE == 3,
                       "TAG_NULL/FALSE/TRUE + 1 must equal IS_NULL/FALSE/TRUE");
    if (tag <= TAG_TRUE) {
        Z_TYPE_INFO_P(out) = (uint32_t)tag + 1u;
        return 0;
    }
    switch (tag) {
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
        case TAG_STR_INLINE:
            return dec_decode_str_inline(d, out);
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
        int rc = decode_scalar_tag(d, out, tag);
        if (UNEXPECTED(rc < 0)) {
            ZVAL_UNDEF(out);
        }
        return rc;
    }
    if (UNEXPECTED(PHPSER_HAS_OOM())) {
        ZVAL_UNDEF(out);
        return -1;
    }
    d->pos--;
    return decode_value(d, out);
}

/* Return 1 for an absent key, 0 after replacement, or -1 on budget exhaustion.
 * Absent keys and failures leave `value` owned by the caller. */
static zend_never_inline int dec_assoc_update_collision(
    decode_ctx *d, HashTable *ht, zend_ulong h, zend_string *zs,
    zval *value, uint32_t idx)
{
    uint32_t chain_len = 0, collisions = 0;
    while (idx != HT_INVALID_IDX) {
        Bucket *bucket = HT_HASH_TO_BUCKET(ht, idx);
        int equal = 0;
        if (bucket->h == h) {
            if (zs == NULL) {
                equal = bucket->key == NULL;
            } else if (bucket->key != NULL) {
                equal = dec_strings_equal(d, bucket->key, zs);
                if (UNEXPECTED(equal < 0)) return -1;
                if (UNEXPECTED(++collisions >= MAX_HASH_CHAIN_LENGTH) && !equal) return -1;
            }
        }
        if (equal) {
            dec_note_drop(d, &bucket->val);
            zval_ptr_dtor(&bucket->val);
            ZVAL_COPY_VALUE(&bucket->val, value);
            return 0;
        }
        if (UNEXPECTED(++chain_len == MAX_HASH_CHAIN_LENGTH)) {
            if (dec_charge_comparison(d, chain_len) < 0) return -1;
            chain_len = 0;
        }
        idx = Z_NEXT(bucket->val);
    }
    return 1;
}

/* Update a fresh, pre-sized TAG_ASSOC table while bounding the same bucket
 * walk zend_hash_update would perform internally. Returning -1 leaves `value`
 * owned by the caller. */
static zend_always_inline int dec_assoc_update_bounded(
    decode_ctx *d, HashTable *ht, key_val *key, zval *value)
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
    if (idx != HT_INVALID_IDX) {
        int rc = dec_assoc_update_collision(d, ht, h, zs, value, idx);
        if (rc <= 0) return rc;
    }

    uint32_t new_idx = ht->nNumUsed++;
    ht->nNumOfElements++;
    Bucket *bucket = ht->arData + new_idx;
    bucket->h = h;
    bucket->key = zs;
    if (zs != NULL && !ZSTR_IS_INTERNED(zs)) {
        if (key->kind == KV_OWNED_STR) {
            /* Transfer inline key ownership directly to the bucket. */
            key->kind = KV_DICT_STR;
        } else {
            zend_string_addref(zs);
        }
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
#ifdef ZEND_CHECK_STACK_LIMIT
    /* Rejected like over-deep input: the remaining C stack, not MAX_DEPTH,
     * is the binding limit on small thread stacks. */
    if (UNEXPECTED(d->depth >= PHPSER_STACK_CHECK_DEPTH)
        && UNEXPECTED(zend_call_stack_overflowed(EG(stack_limit)))) {
        ZVAL_UNDEF(out);
        return -1;
    }
#endif
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
    const uint8_t *buf = d->buf;
    size_t len = d->len, pos = d->pos;
    switch (col_tag) {
        case TAG_PACKED_LONGS:
            for (; i < nrows; i++) {
                int64_t lv;
                if (varint_read_i64_fast(buf, len, &pos, &lv) < 0
                    || UNEXPECTED(!dec_i64_fits_zend_long(lv))) {
                    goto fail;
                }
                ZVAL_LONG(&col[i], lv);
            }
            d->pos = pos;
            return 0;
        case TAG_PACKED_DOUBLES:
            if (nrows > (len - pos) / 8) goto fail;
            for (; i < nrows; i++) {
                ZVAL_DOUBLE(&col[i], le64_read(buf + pos));
                pos += 8;
            }
            d->pos = pos;
            return 0;
        case TAG_PACKED_STRINGS: {
            zend_string **dict = d->dict;
            uint32_t dict_len = d->dict_len;
            for (; i < nrows; i++) {
                uint64_t idx;
                if (varint_read_u64(buf, len, &pos, &idx) < 0
                    || UNEXPECTED(idx >= dict_len)) {
                    goto fail;
                }
                ZVAL_STR_COPY(&col[i], dict[idx]);
            }
            d->pos = pos;
            return 0;
        }
        case TAG_PACKED_DELTA: {
            uint64_t acc = 0;
            for (; i < nrows; i++) {
                int64_t dv;
                if (varint_read_i64_fast(buf, len, &pos, &dv) < 0) goto fail;
                acc += (uint64_t)dv;
                if (UNEXPECTED(!dec_i64_fits_zend_long((int64_t)acc))) goto fail;
                ZVAL_LONG(&col[i], (zend_long)(int64_t)acc);
            }
            d->pos = pos;
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

/* Decode columns 1..ncols-1 directly into each row's bucket value slot. Every
 * target slot starts as IS_UNDEF from the row template clone, and ZVAL_* macros
 * preserve u2.next, so a failure at row i leaves [i, nrows) safe for destroy. */
static int dec_table_column_direct(decode_ctx *d, Bucket **row_data, uint32_t col_idx,
                                   uint64_t nrows, uint8_t col_tag) {
    const uint8_t *buf = d->buf;
    size_t len = d->len, pos = d->pos;
    switch (col_tag) {
        case TAG_PACKED_LONGS:
            for (uint64_t r = 0; r < nrows; r++) {
                int64_t lv;
                if (varint_read_i64_fast(buf, len, &pos, &lv) < 0
                    || UNEXPECTED(!dec_i64_fits_zend_long(lv))) {
                    return -1;
                }
                ZVAL_LONG(&row_data[r][col_idx].val, lv);
            }
            d->pos = pos;
            return 0;
        case TAG_PACKED_DOUBLES:
            if (nrows > (len - pos) / 8) return -1;
            for (uint64_t r = 0; r < nrows; r++) {
                ZVAL_DOUBLE(&row_data[r][col_idx].val, le64_read(buf + pos));
                pos += 8;
            }
            d->pos = pos;
            return 0;
        case TAG_PACKED_STRINGS: {
            zend_string **dict = d->dict;
            uint32_t dict_len = d->dict_len;
            for (uint64_t r = 0; r < nrows; r++) {
                uint64_t idx;
                if (varint_read_u64(buf, len, &pos, &idx) < 0
                    || UNEXPECTED(idx >= dict_len)) {
                    return -1;
                }
                ZVAL_STR_COPY(&row_data[r][col_idx].val, dict[idx]);
            }
            d->pos = pos;
            return 0;
        }
        case TAG_PACKED_DELTA: {
            uint64_t acc = 0;
            for (uint64_t r = 0; r < nrows; r++) {
                int64_t dv;
                if (varint_read_i64_fast(buf, len, &pos, &dv) < 0) return -1;
                acc += (uint64_t)dv;
                if (UNEXPECTED(!dec_i64_fits_zend_long((int64_t)acc))) return -1;
                ZVAL_LONG(&row_data[r][col_idx].val, (zend_long)(int64_t)acc);
            }
            d->pos = pos;
            return 0;
        }
        case TAG_PACKED_MIXED:
            for (uint64_t r = 0; r < nrows; r++) {
                if (decode_value_hot(d, &row_data[r][col_idx].val) < 0) return -1;
            }
            return 0;
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
            id_slot raw = d->id_table[id];
            switch (ID_SLOT_TAG(raw)) {
                case ID_TAG_OBJ:  ZVAL_OBJ_COPY(out, (zend_object *)ID_SLOT_PTR(raw)); return 0;
                case ID_TAG_REF: {
                    zend_reference *ref = (zend_reference *)ID_SLOT_PTR(raw);
                    d->refs_seen++;
                    ZVAL_REF(out, ref);
                    GC_ADDREF(ref);
                    return 0;
                }
                case ID_TAG_ARR: {
                    zend_array *arr = (zend_array *)ID_SLOT_PTR(raw);
                    ZVAL_ARR(out, arr);
                    GC_ADDREF(arr);
                    return 0;
                }
                case ID_TAG_SPECIAL:
                    if (raw == ID_SLOT_EMPTY_ARR) {
                        ZVAL_EMPTY_ARRAY(out);
                    } else {
                        ZVAL_NULL(out);
                    }
                    return 0;
            }
            return -1;
        }
        case TAG_NEW_REF: {
            /* Publishing a nested wrapper exposes a ref-to-ref to callbacks
             * and typed-property verification before the inner decode returns. */
            if (d->pos >= d->len || d->buf[d->pos] == TAG_NEW_REF) return -1;
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
            d->refs_seen++;
            if (decode_value(d, &ref->val) < 0) {
                /* Back-references may already expose this value to destructors. */
                ZVAL_NULL(&ref->val);
                return -1;
            }
            /* PHP cannot produce a reference directly wrapping another reference.
             * Crafted nested/self references recurse during teardown; reject and
             * clear the inner edge so id-table cleanup can release it. Legitimate
             * cycles pass through arrays or objects and use cyclic GC. */
            if (Z_TYPE(ref->val) == IS_REFERENCE) {
                zval_ptr_dtor(&ref->val);
                ZVAL_NULL(&ref->val);
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
        case TAG_PACKED_LONGS:
            return dec_decode_packed_longs(d, out);
        case TAG_PACKED_DOUBLES:
            return dec_decode_packed_doubles(d, out);
        case TAG_PACKED_STRINGS:
            return dec_decode_packed_strings(d, out);
        case TAG_PACKED_MIXED: {
            uint64_t n;
            if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
            if (n > UINT32_MAX || n > d->len - d->pos) return -1;
            zend_array *arr = dec_new_array((uint32_t)n, DEC_INIT_PACKED);
            if (UNEXPECTED(!arr)) return -1;
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
        case TAG_PACKED_DELTA:
            return dec_decode_packed_delta(d, out);
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
            zend_array *arr = dec_new_array((uint32_t)n, DEC_INIT_PACKED);
            if (UNEXPECTED(!arr)) return -1;
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
            zend_class_entry *ce = dec_class_resolve_obj(d, class_idx, class_name);
            if (UNEXPECTED(EG(exception))) return -1;
            if (!ce || ce->unserialize == NULL) {
                /* Unknown class or no C-level unserializer: skip the payload,
                 * yield NULL, and register a NULL id slot so later TAG_REFs
                 * resolve to NULL. */
                d->pos += blen;
                ZVAL_NULL(out);
                dec_register(d, out);
                return 0;
            }
            if (dec_ce_uninstantiable(ce)) {
                return -1;
            }
            const unsigned char *payload = d->buf + d->pos;
            d->pos += blen;
            /* Conservative: the hook receives only its own fresh object and a
             * string, but it runs user code during the decode. */
            d->pins_may_orphan = true;
            if (dec_unserialize_legacy(d, out, ce, payload, (size_t)blen) != SUCCESS) {
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
                ? dec_class_resolve_obj(d, class_idx, class_name) : NULL;
            if (UNEXPECTED(EG(exception))) return -1;
            /* Unknown class: decode to __PHP_Incomplete_Class with the original
             * name preserved, like a denied class, so a re-encode can recover
             * it. Uniform across OBJECT, MAGIC, and SLOTS. */
            int known = (ce != NULL);
            if (!ce) ce = PHP_IC_ENTRY;

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
            if (!allowed || !known) php_store_class_name(out, class_name);
            uint8_t hook = dec_prepare_hook(Z_OBJ_P(out));
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
            if (allowed && hook == HOOK_UNSERIALIZE) {
                dec_defer_unserialize(d, Z_OBJ_P(out), &data);
                /* Ownership of `data` transferred to deferred list. */
            } else {
                /* Without __unserialize, preserve decoded data as properties,
                 * including on incomplete classes. */
                if (dec_apply_data_as_props(d, Z_OBJ_P(out), Z_ARRVAL(data)) < 0) {
                    /* A typed slot rejected the data (pending TypeError). `out`
                     * is registered, so decode_destroy releases it. Do not
                     * queue __wakeup. */
                    zval_ptr_dtor(&data);
                    return -1;
                }
                /* A SHARED_ARRAY data table is pinned; dropping it may leave the
                 * pin as its only holder. A fresh table orphans nothing. */
                if (GC_REFCOUNT(Z_ARR(data)) > 1) dec_note_drop(d, &data);
                zval_ptr_dtor(&data);
                /* Like native, a class with __serialize() + __wakeup() but no
                 * __unserialize() still gets __wakeup(). Gated on `allowed` so
                 * incomplete classes never run a hook. */
                if (allowed && hook == HOOK_WAKEUP) {
                    dec_defer_wakeup(d, Z_OBJ_P(out));
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
            if (UNEXPECTED(EG(exception))) return -1;
            if (!ce || !(ce->ce_flags & ZEND_ACC_ENUM)) return -1;
            zend_class_constant *cc = zend_hash_find_ptr(CE_CONSTANTS_TABLE(ce), casename);
            if (!cc || !(ZEND_CLASS_CONST_FLAGS(cc) & ZEND_CLASS_CONST_IS_CASE)) return -1;
            if (Z_TYPE(cc->value) == IS_CONSTANT_AST
                && zval_update_constant_ex(&cc->value, cc->ce) == FAILURE) {
                return -1;
            }
            if (UNEXPECTED(Z_TYPE(cc->value) != IS_OBJECT)) return -1;
            ZVAL_COPY(out, &cc->value);
            dec_register(d, out);
            return 0;
        }
        case TAG_OBJECT_SLOTS:
            return dec_decode_object_slots(d, out);
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
                ? dec_class_resolve_obj(d, class_idx, class_name) : NULL;
            if (UNEXPECTED(EG(exception))) return -1;
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
            uint8_t hook = dec_prepare_hook(obj);
            /* Register before decoding properties so a back-ref inside a
             * property value (cycles, shared subobjects) can resolve to this
             * in-progress object. */
            dec_register(d, out);

            /* The current class definition controls reconstruction, regardless of wire
             * form: __unserialize receives the properties array and suppresses
             * __wakeup. Raw property writes would bypass its invariants. */
            if (allowed && hook == HOOK_UNSERIALIZE) {
                zend_array *data_arr = dec_new_array((uint32_t)nprops, DEC_INIT_MIXED);
                if (UNEXPECTED(!data_arr)) {
                    zval_ptr_dtor(out); ZVAL_NULL(out);
                    return -1;
                }
                zval data;
                ZVAL_ARR(&data, data_arr);
                for (uint64_t i = 0; i < nprops; i++) {
                    uint64_t key_idx;
                    if (varint_read_u64(d->buf, d->len, &d->pos, &key_idx) < 0) goto unser_fail;
                    zend_string *key = dec_get_zstr(d, key_idx);
                    if (!key) goto unser_fail;
                    zval tmp;
                    if (decode_value_hot(d, &tmp) < 0) goto unser_fail;
                    key_val data_key = { .kind = KV_DICT_STR, .str = key };
                    if (UNEXPECTED(dec_assoc_update_bounded(
                            d, Z_ARRVAL(data), &data_key, &tmp) < 0)) {
                        zval_ptr_dtor(&tmp);
                        goto unser_fail;
                    }
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
                zend_property_info *info;
                if (UNEXPECTED(dec_prop_info_for_key(d, ce, key, &info) < 0)) goto obj_fail;
                /* Reject a forbidden dynamic property at the key, as native
                 * does; decoding the value first could autoload a class. */
                if (UNEXPECTED(dec_prop_forbidden(obj, obj_props, key, info))) {
                    goto obj_fail;
                }
                zval tmp;
                if (decode_value_hot(d, &tmp) < 0) goto obj_fail;
                if (EXPECTED(obj_props == NULL)) {
                    if (EXPECTED(info != NULL)) {
                        /* A duplicate wire key can rewrite a decoded value. */
                        if (dec_install_declared_slot(d, obj, info, &tmp) < 0) {
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
                if (dec_install_prop_resolved(d, obj, obj_props, key, info, &tmp) < 0) goto obj_fail;
                continue;
            obj_fail:
                zval_ptr_dtor(out); ZVAL_NULL(out); return -1;
            }
            /* Defer __wakeup until the full graph, including cycle back-edges,
             * is stitched. */
            if (hook == HOOK_WAKEUP) dec_defer_wakeup(d, obj);
            return 0;
        }
        case TAG_ASSOC_DICT:
            return dec_decode_assoc_dict(d, out);
        case TAG_ASSOC: {
            uint64_t n;
            if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
            /* Each assoc entry is at least 2 bytes (key tag + value tag). */
            if (n > UINT32_MAX || n > (d->len - d->pos) / 2) return -1;
            if (n == 0) {
                ZVAL_EMPTY_ARRAY(out);
                return 0;
            }
            zend_array *arr = dec_new_array((uint32_t)n, DEC_INIT_MIXED);
            if (UNEXPECTED(!arr)) return -1;
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
                if (UNEXPECTED(dec_assoc_update_bounded(d, arr, &k, &tmp) < 0)) {
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
        case TAG_SHARED_ARRAY:
            return dec_decode_shared_array(d, out);
        default:
            return -1;
    }
}

/* The inner value decodes at the wrapper's depth, matching the encoder, which
 * emits the prefix inside the array's own encode_value_ex frame. The id is
 * claimed only after the array is complete, so no TAG_REF can reach an array
 * that is still being built: one from inside it is out of range. */
static zend_never_inline int dec_decode_shared_array(decode_ctx *d, zval *out) {
    if (d->pos >= d->len || !dec_is_array_tag(d->buf[d->pos])) return -1;
    uint32_t refs_before = d->refs_seen;
    if (decode_value_inner(d, out) < 0) return -1;
    if (UNEXPECTED(Z_TYPE_P(out) != IS_ARRAY)) return -1;
    /* The current walk must create or visit no PHP reference, including in
     * nested arrays and new objects. Back-references to an existing object
     * preserve its property aliases without traversing them. The encoder can
     * retry an unshared array once those object identities are registered. */
    if (UNEXPECTED(d->refs_seen != refs_before)) return -1;
    dec_register(d, out);
    return 0;
}

static zend_never_inline int dec_decode_packed_longs(decode_ctx *d, zval *out) {
    uint64_t n;
    if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
    if (n > UINT32_MAX || n > d->len - d->pos) return -1;
    zend_array *arr = dec_new_array((uint32_t)n, DEC_INIT_PACKED);
    if (UNEXPECTED(!arr)) return -1;
    const uint8_t *buf = d->buf;
    size_t len = d->len, pos = d->pos;
    zval *cells = arr->arPacked;
    for (uint64_t i = 0; i < n; i++) {
        int64_t lv;
        if (varint_read_i64_fast(buf, len, &pos, &lv) < 0
            || UNEXPECTED(!dec_i64_fits_zend_long(lv))) {
            zend_array_destroy(arr);
            return -1;
        }
        ZVAL_LONG(&cells[i], lv);
    }
    d->pos = pos;
    dec_finish_packed(arr, n, out);
    return 0;
}

static zend_never_inline int dec_decode_packed_doubles(decode_ctx *d, zval *out) {
    uint64_t n;
    if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
    if (n > UINT32_MAX || n > (d->len - d->pos) / 8) return -1;
    zend_array *arr = dec_new_array((uint32_t)n, DEC_INIT_PACKED);
    if (UNEXPECTED(!arr)) return -1;
    const uint8_t *buf = d->buf;
    size_t pos = d->pos;
    zval *cells = arr->arPacked;
    for (uint64_t i = 0; i < n; i++) {
        ZVAL_DOUBLE(&cells[i], le64_read(buf + pos));
        pos += 8;
    }
    d->pos = pos;
    dec_finish_packed(arr, n, out);
    return 0;
}

static zend_never_inline int dec_decode_packed_strings(decode_ctx *d, zval *out) {
    uint64_t n;
    if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
    if (n > UINT32_MAX || n > d->len - d->pos) return -1;
    zend_array *arr = dec_new_array((uint32_t)n, DEC_INIT_PACKED);
    if (UNEXPECTED(!arr)) return -1;
    const uint8_t *buf = d->buf;
    size_t len = d->len, pos = d->pos;
    zend_string **dict = d->dict;
    uint32_t dict_len = d->dict_len;
    zval *cells = arr->arPacked;
    for (uint64_t i = 0; i < n; i++) {
        uint64_t idx;
        if (varint_read_u64(buf, len, &pos, &idx) < 0
            || UNEXPECTED(idx >= dict_len)) {
            arr->nNumUsed = (uint32_t)i;
            zend_array_destroy(arr);
            return -1;
        }
        ZVAL_STR_COPY(&cells[i], dict[idx]);
    }
    d->pos = pos;
    dec_finish_packed(arr, n, out);
    return 0;
}

static zend_never_inline int dec_decode_object_slots(decode_ctx *d, zval *out) {
    uint64_t class_idx, nprops;
    if (varint_read_u64(d->buf, d->len, &d->pos, &class_idx) < 0) return -1;
    if (varint_read_u64(d->buf, d->len, &d->pos, &nprops) < 0) return -1;
    if (nprops > UINT32_MAX || nprops > d->len - d->pos) return -1;
    zend_string *class_name = dec_get_class_name(d, class_idx);
    if (!class_name) return -1;

    int allowed = dec_class_allowed(d, class_idx, class_name);

    if (UNEXPECTED(!allowed)) {
        /* Denied classes must not autoload. Resident classes supply slot
         * names; without a resident schema, consume values into a
         * property-less incomplete object. */
        zend_class_entry *schema_ce =
            dec_class_resolve_ex(d, class_idx, class_name, /* autoload */ 0);
        if (dec_make_incomplete(out, class_name) < 0) return -1;
        dec_register(d, out);
        zend_object *obj = Z_OBJ_P(out);
        if (schema_ce) {
            dec_class_slot *scs = &d->class_slots[class_idx];
            uint32_t current_nprops = scs->slot_count;
            if (nprops > current_nprops) goto slots_fail;
            HashTable *obj_props = zend_std_get_properties(obj);
            int pc = ce_table_slot_prefix_limit(
                schema_ce, (uint32_t)nprops, current_nprops);
            for (int pi = 0; pi < pc; pi++) {
                zend_property_info *info = schema_ce->properties_info_table[pi];
                if (info == NULL) continue;
                zval tmp;
                if (decode_value_hot(d, &tmp) < 0) goto slots_fail;
                if (dec_install_prop(d, obj, obj_props, info->name, &tmp) < 0) {
                    goto slots_fail;
                }
            }
        } else {
            /* Discarded values may have later back-references; id-table
             * pins keep them alive until decode_destroy. */
            for (uint64_t i = 0; i < nprops; i++) {
                zval tmp;
                if (decode_value_hot(d, &tmp) < 0) goto slots_fail;
                dec_note_drop(d, &tmp);
                zval_ptr_dtor(&tmp);
            }
        }
        return 0;
    }

    dec_class_slot *cs = &d->class_slots[class_idx];
    zend_class_entry *ce = cs->ce;
    if (UNEXPECTED(!ce)) {
        ce = dec_class_resolve_obj(d, class_idx, class_name);
        if (UNEXPECTED(EG(exception))) return -1;
        if (!ce) {
            if (dec_make_incomplete(out, class_name) < 0) return -1;
            dec_register(d, out);
            for (uint64_t i = 0; i < nprops; i++) {
                zval tmp;
                if (decode_value_hot(d, &tmp) < 0) goto slots_fail;
                dec_note_drop(d, &tmp);
                zval_ptr_dtor(&tmp);
            }
            return 0;
        }
        cs = &d->class_slots[class_idx];
    }

    /* Fast path: plain class with dense slots, no hooks, no destructor, no
     * custom create_object, constants updated, and every slot supplied by the
     * wire. Allocate via zend_objects_new without copying default properties,
     * zero slots to IS_UNDEF (GC-safe), and decode directly into the table. */
    if (EXPECTED((cs->flags & DEC_CS_SLOTS_FAST)
        && (ce->ce_flags & ZEND_ACC_CONSTANTS_UPDATED)
        && nprops == cs->slot_count)) {
        zend_object *obj = zend_objects_new(ce);
        int pc = ce->default_properties_count;
        if (pc > 0) {
            memset(obj->properties_table, 0, (size_t)pc * sizeof(zval));
        }
        ZVAL_OBJ(out, obj);
        dec_register_obj(d, obj);
        zend_property_info **infos = ce->properties_info_table;
        zval *slots = obj->properties_table;
        for (int pi = 0; pi < pc; pi++) {
            zend_property_info *info = infos[pi];
            zval *slot = &slots[pi];
            if (UNEXPECTED(decode_value_hot(d, slot) < 0)) goto slots_fail;
            if (ZEND_TYPE_IS_SET(info->type)) {
                if (UNEXPECTED(!dec_prop_type_fast_accept(info, slot))) {
                    if (UNEXPECTED(PHPSER_HAS_OOM())
                        || UNEXPECTED(!zend_verify_prop_assignable_by_ref(info, slot, /*strict*/ 1))) {
                        i_zval_ptr_dtor(slot);
                        ZVAL_UNDEF(slot);
                        goto slots_fail;
                    }
                    if (Z_ISREF_P(slot)) {
                        ZEND_REF_ADD_TYPE_SOURCE(Z_REF_P(slot), info);
                    }
                }
            }
        }
        return 0;
    }

    uint32_t current_nprops = cs->slot_count;
    if (nprops > current_nprops) return -1;
    if (cs->flags & (DEC_CS_UNINST | DEC_CS_LEGACY_ONLY)) {
        return -1;
    }

    if (object_init_ex(out, ce) != SUCCESS) {
        ZVAL_NULL(out);
        return -1;
    }
    zend_object *obj = Z_OBJ_P(out);
    uint8_t hook = cs->hook;
    if (hook != HOOK_NONE && ce->destructor != NULL) {
        GC_ADD_FLAGS(obj, IS_OBJ_DESTRUCTOR_CALLED);
    }
    dec_register_obj(d, obj);
    int pc = ce_table_slot_prefix_limit(ce, (uint32_t)nprops, current_nprops);

    /* A class can gain __unserialize after encoding. Follow its current
     * definition: pass named slot values to the hook and suppress raw
     * writes and __wakeup. */
    if (hook == HOOK_UNSERIALIZE) {
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

    /* properties_info_table is indexed by slot number, so each slot is
     * written once and holds no value written by this decode. */
    for (int pi = 0; pi < pc; pi++) {
        zend_property_info *info = ce->properties_info_table[pi];
        if (info == NULL) continue;
        zval tmp;
        if (decode_value_hot(d, &tmp) < 0) goto slots_fail;
        if (dec_install_default_slot(obj, info, &tmp) < 0) goto slots_fail;
    }
    if (hook == HOOK_WAKEUP) dec_defer_wakeup(d, obj);
    return 0;
slots_fail:
    if (Z_TYPE_P(out) != IS_UNDEF) {
        zval_ptr_dtor(out);
        ZVAL_NULL(out);
    }
    return -1;
}

static zend_never_inline int dec_decode_assoc_dict(decode_ctx *d, zval *out) {
    uint64_t n;
    if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
    /* Wire layout: n key indices, then n values. Each entry needs at least
     * one byte of key idx and one byte of value tag. */
    if (n > UINT32_MAX || n > (d->len - d->pos) / 2) return -1;
    if (n == 0) {
        ZVAL_EMPTY_ARRAY(out);
        return 0;
    }
    zend_string *keys_stack[32];
    zend_string **keys = dec_read_schema_keys(d, n, keys_stack, 32);
    if (!keys) return -1;
    zend_array *arr = dec_new_array((uint32_t)n, DEC_INIT_MIXED);
    if (UNEXPECTED(!arr)) {
        if (keys != keys_stack) efree(keys);
        return -1;
    }
    uint32_t count = (uint32_t)n;
    uint32_t mask = arr->nTableMask;
    Bucket *buckets = arr->arData;
    bool static_keys = true;
    for (uint32_t i = 0; i < count; i++) {
        Bucket *b = buckets + i;
        if (UNEXPECTED(decode_value_hot(d, &b->val) < 0)) {
            arr->nNumUsed = i;
            arr->nNumOfElements = i;
            if (!static_keys) HT_FLAGS(arr) &= ~HASH_FLAG_STATIC_KEYS;
            if (keys != keys_stack) efree(keys);
            zend_array_destroy(arr);
            return -1;
        }
        zend_string *k = keys[i];
        zend_ulong h = zend_string_hash_val(k);
        b->h = h;
        b->key = k;
        if (!ZSTR_IS_INTERNED(k)) {
            zend_string_addref(k);
            static_keys = false;
        }
        uint32_t nIndex = (uint32_t)h | mask;
        Z_NEXT(b->val) = HT_HASH(arr, nIndex);
        HT_HASH(arr, nIndex) = HT_IDX_TO_HASH(i);
    }
    arr->nNumUsed = count;
    arr->nNumOfElements = count;
    if (!static_keys) HT_FLAGS(arr) &= ~HASH_FLAG_STATIC_KEYS;
    if (keys != keys_stack) efree(keys);
    ZVAL_ARR(out, arr);
    return 0;
}

static zend_never_inline int dec_decode_packed_delta(decode_ctx *d, zval *out) {
    uint64_t n;
    if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
    /* v0 plus n-1 deltas is at least n wire bytes, the same linear
     * bound as PACKED_LONGS. */
    if (n > UINT32_MAX || n > d->len - d->pos) return -1;
    zend_array *arr = dec_new_array((uint32_t)n, DEC_INIT_PACKED);
    if (UNEXPECTED(!arr)) return -1;
    /* First varint is v0, the rest are deltas; acc starts at zero so
     * one wrapping add covers both. Long cells own nothing, so the
     * failure destroy needs no nNumUsed bookkeeping. */
    uint64_t acc = 0;
    /* Locals: the cell stores may alias d->pos and arr->arPacked, so the
     * compiler would otherwise store and reload the cursor on every element,
     * a store-to-load chain across iterations. */
    const uint8_t *buf = d->buf;
    size_t len = d->len, pos = d->pos;
    zval *cells = arr->arPacked;
    for (uint64_t i = 0; i < n; i++) {
        int64_t dv;
        if (varint_read_i64_fast(buf, len, &pos, &dv) < 0) {
            zend_array_destroy(arr);
            return -1;
        }
        acc += (uint64_t)dv;
        if (UNEXPECTED(!dec_i64_fits_zend_long((int64_t)acc))) {
            zend_array_destroy(arr);
            return -1;
        }
        ZVAL_LONG(&cells[i], (zend_long)(int64_t)acc);
    }
    d->pos = pos;
    dec_finish_packed(arr, n, out);
    return 0;
}

static zend_never_inline int dec_decode_table(decode_ctx *d, zval *out) {
    uint64_t nrows, ncols;
    if (varint_read_u64(d->buf, d->len, &d->pos, &nrows) < 0) return -1;
    if (varint_read_u64(d->buf, d->len, &d->pos, &ncols) < 0) return -1;
    if (ncols > UINT32_MAX || nrows > UINT32_MAX || ncols == 0) return -1;
    if (ncols > HT_MAX_SIZE) return -1;
    uint64_t row_bytes = phpser_mixed_array_bytes((uint32_t)ncols) + 48;
    if (nrows > d->materialize_left / row_bytes) return -1;
    d->materialize_left -= (size_t)(nrows * row_bytes);
    if (ncols > (d->len - d->pos) / 2) return -1;
    /* Each of the nrows*ncols cells is at least one byte on the wire. */
    if (nrows > (d->len - d->pos) / ncols) return -1;

    zend_string *keys_stack[32];
    zend_string **keys = dec_read_schema_keys(d, ncols, keys_stack, 32);
    if (!keys) return -1;

    /* Materialize rows only after column 0 decodes so a malformed frame fails
     * before allocating nrows row HashTables. Columns 1..ncols-1 decode
     * directly into the cloned rows' bucket slots. */
    if (d->pos >= d->len) {
        if (keys != keys_stack) efree(keys);
        return -1;
    }
    uint8_t col0_tag = d->buf[d->pos++];
    zval *colbuf = (zval *)dec_safe_emalloc((size_t)nrows, sizeof(zval));
    if (UNEXPECTED(!colbuf)) {
        if (keys != keys_stack) efree(keys);
        return -1;
    }
    if (dec_table_column(d, colbuf, nrows, col0_tag) < 0) {
        for (uint64_t r = 0; r < nrows; r++) i_zval_ptr_dtor(&colbuf[r]);
        efree(colbuf);
        if (keys != keys_stack) efree(keys);
        return -1;
    }

    zend_array *outer = dec_new_array((uint32_t)nrows, DEC_INIT_PACKED);
    if (UNEXPECTED(!outer)) {
        for (uint64_t r = 0; r < nrows; r++) i_zval_ptr_dtor(&colbuf[r]);
        efree(colbuf);
        if (keys != keys_stack) efree(keys);
        return -1;
    }

    Bucket *row_data_stack[64];
    Bucket **row_data = NULL;
    if (ncols > 1) {
        row_data = nrows <= 64
            ? row_data_stack
            : (Bucket **)dec_safe_emalloc((size_t)nrows, sizeof(Bucket *));
        if (UNEXPECTED(!row_data)) {
            for (uint64_t r = 0; r < nrows; r++) i_zval_ptr_dtor(&colbuf[r]);
            efree(colbuf);
            if (keys != keys_stack) efree(keys);
            zend_array_destroy(outer);
            return -1;
        }
    }

    /* Build row 0 as the template (hash slots + bucket headers + IS_UNDEF vals),
     * then clone rows 1..nrows-1 with one contiguous memcpy of the hash table
     * and used buckets. */
    uint64_t built = 0;
    if (nrows > 0 && !PHPSER_HAS_OOM()) {
        zend_array *row0 = dec_new_array((uint32_t)ncols, DEC_INIT_MIXED);
        if (EXPECTED(row0 != NULL)) {
            int tpl_static_keys = 1;
            uint32_t mask = row0->nTableMask;
            Bucket *b0 = row0->arData;
            for (uint32_t k = 0; k < (uint32_t)ncols; k++) {
                zend_ulong h = zend_string_hash_val(keys[k]);
                uint32_t nIndex = (uint32_t)h | mask;
                Bucket *b = b0 + k;
                b->h = h;
                b->key = keys[k];
                ZVAL_UNDEF(&b->val);
                Z_NEXT(b->val) = HT_HASH(row0, nIndex);
                HT_HASH(row0, nIndex) = HT_IDX_TO_HASH(k);
                if (!ZSTR_IS_INTERNED(keys[k])) {
                    tpl_static_keys = 0;
                }
            }
            row0->nNumUsed = (uint32_t)ncols;
            row0->nNumOfElements = (uint32_t)ncols;
            if (!tpl_static_keys) {
                HT_FLAGS(row0) &= ~HASH_FLAG_STATIC_KEYS;
            }
            ZEND_ASSERT(GC_REFCOUNT(row0) == 1 && GC_INFO(row0) == 0);
            ZVAL_ARR(&outer->arPacked[0], row0);
            if (row_data) row_data[0] = b0;
            built = 1;

            const void *tpl_addr = HT_GET_DATA_ADDR(row0);
            size_t ht_alloc_size = HT_SIZE(row0);
            size_t ht_copy_size = HT_USED_SIZE(row0);
            for (uint64_t r = 1; r < nrows && !PHPSER_HAS_OOM(); r++) {
                void *data = dec_safe_emalloc(ht_alloc_size, 1);
                if (UNEXPECTED(!data)) break;
                zend_array *row = (zend_array *)emalloc(sizeof(HashTable));
                *row = *row0;
                HT_SET_DATA_ADDR(row, data);
                memcpy(data, tpl_addr, ht_copy_size);
                ZVAL_ARR(&outer->arPacked[r], row);
                if (row_data) row_data[r] = row->arData;
                built = r + 1;
            }
        }
    }

    /* Take the `built` references for every non-interned schema key in one
     * bump per column. */
    for (uint32_t k = 0; k < (uint32_t)ncols; k++) {
        if (!ZSTR_IS_INTERNED(keys[k])) {
            GC_SET_REFCOUNT(keys[k], GC_REFCOUNT(keys[k]) + (uint32_t)built);
        }
    }
    outer->nNumUsed = (uint32_t)built;
    outer->nNumOfElements = (uint32_t)built;
    outer->nNextFreeElement = (zend_long)built;

    if (UNEXPECTED(built != nrows)) {
        for (uint64_t r = 0; r < nrows; r++) i_zval_ptr_dtor(&colbuf[r]);
        efree(colbuf);
        if (row_data && row_data != row_data_stack) efree(row_data);
        if (keys != keys_stack) efree(keys);
        zend_array_destroy(outer);
        return -1;
    }

    /* Scatter column 0 from colbuf into row[r][0].val and free colbuf. */
    if (row_data) {
        for (uint64_t r = 0; r < nrows; r++) {
            ZVAL_COPY_VALUE(&row_data[r][0].val, &colbuf[r]);
        }
    } else {
        for (uint64_t r = 0; r < nrows; r++) {
            ZVAL_COPY_VALUE(&Z_ARR(outer->arPacked[r])->arData[0].val, &colbuf[r]);
        }
    }
    efree(colbuf);
    if (keys != keys_stack) efree(keys);

    for (uint32_t c = 1; c < (uint32_t)ncols; c++) {
        if (d->pos >= d->len) goto table_fail;
        uint8_t col_tag = d->buf[d->pos++];
        if (dec_table_column_direct(d, row_data, c, nrows, col_tag) < 0) {
            goto table_fail;
        }
    }

    if (row_data && row_data != row_data_stack) efree(row_data);
    ZVAL_ARR(out, outer);
    return 0;

table_fail:
    if (row_data && row_data != row_data_stack) efree(row_data);
    zend_array_destroy(outer);
    return -1;
}

static zend_never_inline int dec_decode_rowset(decode_ctx *d, zval *out) {
    uint64_t nrows, ncols;
    if (varint_read_u64(d->buf, d->len, &d->pos, &nrows) < 0) return -1;
    if (varint_read_u64(d->buf, d->len, &d->pos, &ncols) < 0) return -1;
    if (ncols > UINT32_MAX || nrows > UINT32_MAX || ncols == 0) return -1;
    if (ncols > HT_MAX_SIZE) return -1;
    uint64_t row_bytes = phpser_mixed_array_bytes((uint32_t)ncols) + 48;
    if (nrows > d->materialize_left / row_bytes) return -1;
    d->materialize_left -= (size_t)(nrows * row_bytes);
    if (ncols > (d->len - d->pos) / 2
        || nrows > (d->len - d->pos) / ncols) {
        return -1;
    }

    zend_string *keys_stack[32];
    zend_string **keys = dec_read_schema_keys(d, ncols, keys_stack, 32);
    if (!keys) return -1;

    zend_array *outer = dec_new_array((uint32_t)nrows, DEC_INIT_PACKED);
    if (UNEXPECTED(!outer)) {
        if (keys != keys_stack) efree(keys);
        return -1;
    }
    uint64_t r;
    for (r = 0; r < nrows; r++) {
        zend_array *row = dec_new_array((uint32_t)ncols, DEC_INIT_LAZY);
        if (UNEXPECTED(!row)) goto rowset_fail;
        for (uint64_t c = 0; c < ncols; c++) {
            zend_string *zs = keys[c];
            zval tmp;
            if (decode_value_hot(d, &tmp) < 0) {
                zend_array_destroy(row);
                goto rowset_fail;
            }
            zend_hash_add_new(row, zs, &tmp);
        }
        ZVAL_ARR(&outer->arPacked[r], row);
    }

    if (keys != keys_stack) efree(keys);
    outer->nNumUsed = (uint32_t)nrows;
    outer->nNumOfElements = (uint32_t)nrows;
    outer->nNextFreeElement = (zend_long)nrows;
    ZVAL_ARR(out, outer);
    return 0;

rowset_fail:
    if (keys != keys_stack) efree(keys);
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
static int dec_schema_keys_are_unique(decode_ctx *d, zend_string **keys, uint64_t nkeys) {
    if (nkeys <= 1) {
        return 1;
    }

    if (nkeys <= SCHEMA_UNIQ_SCAN_MAX) {
        for (uint64_t i = 1; i < nkeys; i++) {
            for (uint64_t j = 0; j < i; j++) {
                int equal = dec_strings_equal(d, keys[i], keys[j]);
                if (equal < 0) return -1;
                if (equal) {
                    return 0;
                }
            }
        }
        return 1;
    }

    HashTable seen;
    zend_hash_init(&seen, (uint32_t)nkeys, NULL, NULL, 0);
#ifdef ZEND_HAS_OOM
    if (DEC_FALLIBLE(nkeys, DEC_MIXED_SLOT_BYTES)
        && zend_hash_real_finit_mixed(&seen, true) == FAILURE) {
        zend_hash_destroy(&seen);
        return -1;
    }
#endif
    for (uint64_t i = 0; i < nkeys; i++) {
        if (UNEXPECTED(dec_string_hash_chain_exhausted(d, &seen, keys[i]))) {
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

static zend_string **dec_read_schema_keys(decode_ctx *d, uint64_t nkeys,
                                          zend_string **stack_buf, uint32_t stack_cap) {
    zend_string **keys = (nkeys <= stack_cap)
        ? stack_buf
        : (zend_string **)dec_safe_emalloc((size_t)nkeys, sizeof(zend_string *));
    if (UNEXPECTED(!keys)) return NULL;
    int has_numeric = 0;
    const uint8_t *buf = d->buf;
    size_t len = d->len, pos = d->pos;
    zend_string **dict = d->dict;
    uint32_t dict_len = d->dict_len;
    for (uint64_t i = 0; i < nkeys; i++) {
        uint64_t idx;
        if (varint_read_u64(buf, len, &pos, &idx) < 0
            || UNEXPECTED(idx >= dict_len)) {
            if (keys != stack_buf) efree(keys);
            return NULL;
        }
        keys[i] = dict[idx];
        zend_ulong h;
        if (ZEND_HANDLE_NUMERIC(keys[i], h)) has_numeric = 1;
    }
    d->pos = pos;
    /* Schema keys must be distinct non-numeric strings, including signed frames.
     * Duplicate keys would create phantom buckets; numeric-key update fallback
     * would expose unbudgeted integer hash chains and quadratic decoding.
     * The encoder requires string-key buckets, so valid arrays cannot emit
     * either rejected shape. HMAC verification does not establish uniqueness. */
    int unique = dec_schema_keys_are_unique(d, keys, nkeys);
    if (UNEXPECTED(unique <= 0) || UNEXPECTED(has_numeric)) {
        if (keys != stack_buf) efree(keys);
        return NULL;
    }
    return keys;
}

#if defined(ZEND_CHECK_STACK_LIMIT) && PHP_VERSION_ID < 80400
/* PHP 8.3 keeps zend_call_stack_size_error() static; this throws the same
 * Error. */
static zend_never_inline ZEND_COLD void phpser_stack_size_error(void) {
    zend_throw_error(NULL, "Maximum call stack size of %zu bytes "
        "(zend.max_allowed_stack_size - zend.reserved_stack_size) reached. "
        "Infinite recursion?",
        (size_t) ((uintptr_t) EG(stack_base) - (uintptr_t) EG(stack_limit)));
}
#elif defined(ZEND_CHECK_STACK_LIMIT)
# define phpser_stack_size_error() zend_call_stack_size_error()
#endif

#ifdef ZEND_CHECK_STACK_LIMIT
/* Set while the session handler encodes; holds a reference to the last
 * stack-size Error a nested encode raised, so the handler can tell it from a
 * user-thrown Error with the same message. */
ZEND_TLS bool stack_error_tracking;
ZEND_TLS zend_object *stack_error_raised;

void phpser_stack_error_track(phpser_stack_track *saved) {
    saved->tracking = stack_error_tracking;
    saved->raised = stack_error_raised;
    stack_error_tracking = true;
    stack_error_raised = NULL;
}

bool phpser_stack_error_untrack(const phpser_stack_track *saved) {
    zend_object *raised = stack_error_raised;
    bool ours = raised && EG(exception) == raised;
    if (raised) OBJ_RELEASE(raised);
    stack_error_tracking = saved->tracking;
    stack_error_raised = saved->raised;
    return ours;
}

static void phpser_raise_stack_error(void) {
    phpser_stack_size_error();
    if (stack_error_tracking && EG(exception)) {
        if (stack_error_raised) OBJ_RELEASE(stack_error_raised);
        stack_error_raised = EG(exception);
        GC_ADDREF(stack_error_raised);
    }
}
#endif

/* Shared cleanup for depth/size/stack rejects: free body, destroy ctx (which can
 * run destructors), reclassify as EXCEPTION if cleanup threw, optionally
 * raise the userland exception. */
static void *enc_finish_overflow(
    enc_buf *body, encode_ctx *ctx, bool throw_on_overflow,
    phpser_enc_status *status, phpser_enc_status kind)
{
    if (status) *status = kind;
    enc_buf_free(body);
    bool cleanup_can_throw = ctx->pins_active;
    enc_ctx_destroy(ctx);
    if (UNEXPECTED(cleanup_can_throw && EG(exception))) {
        if (status) *status = PHPSER_ENC_EXCEPTION;
        return NULL;
    }
    if (throw_on_overflow) {
        if (kind == PHPSER_ENC_STACK) {
#ifdef ZEND_CHECK_STACK_LIMIT
            phpser_raise_stack_error();
#endif
        } else if (kind == PHPSER_ENC_DEPTH) {
            zend_throw_exception_ex(zend_ce_exception, 0,
                "phpser: maximum nesting depth (%d) exceeded", MAX_DEPTH);
        } else {
            zend_throw_exception_ex(zend_ce_exception, 0,
                "phpser: string length exceeds the 4 GiB wire-format limit");
        }
    }
    return NULL;
}

/* Keyed top-level arrays up to this size are scanned for array children. */
#define ENC_WRAPPER_MAX_KEYS 16

static uint32_t enc_wrapped_elems(HashTable *ht) {
    uint64_t total = zend_hash_num_elements(ht);
    zval *zv;
    ZEND_HASH_FOREACH_VAL(ht, zv) {
        ZVAL_DEREF(zv);
        if (Z_TYPE_P(zv) == IS_ARRAY) {
            total += zend_hash_num_elements(Z_ARRVAL_P(zv));
        }
    } ZEND_HASH_FOREACH_END();
    return total > UINT32_MAX ? UINT32_MAX : (uint32_t)total;
}

/* Prepare the fixed header + optional string payload slice for a top-level
 * scalar frame. Returns false for non-scalars or >4 GiB strings. */
static zend_always_inline bool enc_scalar_head(
    zval *value, char head[3 + VARINT_MAX_BYTES], size_t *out_head_len,
    const char **out_payload, size_t *out_payload_len)
{
    size_t n = 0;
    const char *payload = NULL;
    size_t payload_len = 0;
    head[n++] = PHPSER_VERSION;
    head[n++] = 0;
    switch (Z_TYPE_P(value)) {
        case IS_NULL:
            head[n++] = TAG_NULL;
            break;
        case IS_FALSE:
            head[n++] = TAG_FALSE;
            break;
        case IS_TRUE:
            head[n++] = TAG_TRUE;
            break;
        case IS_LONG:
            head[n++] = TAG_LONG;
            n = varint_put(head, n, zigzag_encode64(Z_LVAL_P(value)));
            break;
        case IS_DOUBLE:
            head[n++] = TAG_DOUBLE;
            n = le64_put(head, n, Z_DVAL_P(value));
            break;
        case IS_STRING:
            payload_len = Z_STRLEN_P(value);
            if (UNEXPECTED(payload_len > UINT32_MAX)) return false;
            payload = Z_STRVAL_P(value);
            head[n++] = TAG_STR_INLINE;
            n = varint_put(head, n, payload_len);
            break;
        default:
            return false;
    }
    *out_head_len = n;
    *out_payload = payload;
    *out_payload_len = payload_len;
    return true;
}

/* Outlined so top-level scalars in phpser_encode_zval_ex / _raw never allocate
 * the 2+ KiB inline encoder buffers or pay stack-protector canary overhead. */
static zend_never_inline void *phpser_encode_container_core(
    zval *value, bool throw_on_overflow, phpser_enc_status *status,
    size_t tail_reserve, bool raw_mode, size_t *out_len)
{
    zend_string *dict_inline[ENC_DICT_INLINE_CAP];
    intern_slot icache_inline[ENC_ICACHE_INLINE_CAP];
    id_entry id_inline[ENC_ID_INLINE_CAP];
    encode_ctx ctx;
    enc_ctx_init(&ctx, dict_inline, icache_inline, id_inline);

    enc_buf body;
    enc_buf_init(&body);

#ifdef ZEND_CHECK_STACK_LIMIT
    if (UNEXPECTED(zend_call_stack_overflowed(EG(stack_limit)))) {
        return enc_finish_overflow(
            &body, &ctx, throw_on_overflow, status, PHPSER_ENC_STACK);
    }
#endif

    /* Pre-size body only when estimated size exceeds the 1 KiB inline stack
     * buffer; skip pre-sizing for dense numeric packed arrays because
     * TAG_PACKED_AFFINE needs only 7 bytes and LONGS/DELTA/DOUBLES reserve
     * exact worst-case space right before their emit loops. */
    if (Z_TYPE_P(value) == IS_ARRAY) {
        HashTable *ht = Z_ARRVAL_P(value);
        uint32_t n = zend_hash_num_elements(ht);
        bool dense_num = HT_IS_PACKED(ht) && ht->nNumUsed == n && n > 0
            && (Z_TYPE(ht->arPacked[0]) == IS_LONG || Z_TYPE(ht->arPacked[0]) == IS_DOUBLE);
        if (!dense_num) {
            uint32_t n_seed = (n <= ENC_WRAPPER_MAX_KEYS && !HT_IS_PACKED(ht))
                ? enc_wrapped_elems(ht) : n;
            if (n_seed > 64) {
                size_t est = (size_t)n_seed * 16;
                if (est > (256 * 1024)) est = 256 * 1024;
                enc_buf_reserve(&body, est);
            }
        }
    } else if (Z_TYPE_P(value) == IS_OBJECT) {
        zend_class_entry *sce = Z_OBJCE_P(value);
        uint32_t n = (uint32_t)sce->default_properties_count;
        if (Z_OBJ_P(value)->properties) {
            n += zend_hash_num_elements(Z_OBJ_P(value)->properties);
        }
        if (n > 64) {
            size_t est = (size_t)n * 16;
            if (est > (256 * 1024)) est = 256 * 1024;
            enc_buf_reserve(&body, est);
        }
    }

    encode_value(&body, &ctx, value);

    if (UNEXPECTED(ctx.stack_exhausted && !EG(exception) && !PHPSER_HAS_OOM())) {
        return enc_finish_overflow(
            &body, &ctx, throw_on_overflow, status, PHPSER_ENC_STACK);
    }
    if (UNEXPECTED(ctx.failed || PHPSER_HAS_OOM()
        || (ctx.pins_active && EG(exception)))) {
        enc_buf_free(&body);
        enc_ctx_destroy(&ctx);
        if (status) *status = PHPSER_ENC_EXCEPTION;
        return NULL;
    }
    if (UNEXPECTED(ctx.depth_exceeded)) {
        return enc_finish_overflow(
            &body, &ctx, throw_on_overflow, status, PHPSER_ENC_DEPTH);
    }
    if (UNEXPECTED(ctx.size_exceeded)) {
        return enc_finish_overflow(
            &body, &ctx, throw_on_overflow, status, PHPSER_ENC_SIZE);
    }

    size_t body_len = body.len;
    size_t header_len = 1 + varint_len_u64(ctx.dict_len);
    for (uint32_t i = 0; i < ctx.dict_len; i++) {
        size_t elen = ZSTR_LEN(ctx.dict[i]);
        header_len += varint_len_u64(elen) + elen;
    }
    size_t frame_len = header_len + body_len;
    if (UNEXPECTED(frame_len < body_len || tail_reserve > SIZE_MAX - 4096
                   || frame_len > SIZE_MAX - 4096 - tail_reserve)) {
        return enc_finish_overflow(
            &body, &ctx, throw_on_overflow, status, PHPSER_ENC_SIZE);
    }

    void *result;
    char *base;
    if (raw_mode) {
        unsigned char *raw = (unsigned char *)emalloc(frame_len + tail_reserve + 1);
        result = raw;
        base = (char *)raw;
    } else {
        zend_string *res = zend_string_alloc(frame_len + tail_reserve, 0);
        ZSTR_LEN(res) = frame_len;
        result = res;
        base = ZSTR_VAL(res);
    }

    size_t pos = 0;
    base[pos++] = ctx.wire_v2 ? PHPSER_VERSION_V2 : PHPSER_VERSION;
    pos = varint_put(base, pos, ctx.dict_len);
    for (uint32_t i = 0; i < ctx.dict_len; i++) {
        zend_string *zs = ctx.dict[i];
        size_t slen = ZSTR_LEN(zs);
        pos = varint_put(base, pos, slen);
        if (slen) memcpy(base + pos, ZSTR_VAL(zs), slen);
        pos += slen;
    }
    ZEND_ASSERT(pos == header_len);
    if (body_len) {
        memcpy(base + pos, body.data, body_len);
    }
    enc_buf_free(&body);
    base[frame_len] = '\0';

    bool cleanup_can_throw = ctx.pins_active;
    enc_ctx_destroy(&ctx);
    if (UNEXPECTED((cleanup_can_throw && EG(exception)) || PHPSER_HAS_OOM())) {
        if (raw_mode) {
            efree(result);
        } else {
            zend_string_release((zend_string *)result);
        }
        if (status) *status = PHPSER_ENC_EXCEPTION;
        return NULL;
    }
    if (raw_mode) {
        *out_len = frame_len;
    }
    return result;
}

static zend_always_inline void *phpser_encode_zval_core(
    zval *value, bool throw_on_overflow, phpser_enc_status *status,
    size_t tail_reserve, bool raw_mode, size_t *out_len)
{
    if (status) *status = PHPSER_ENC_OK;
    if (out_len) *out_len = 0;

    if (Z_TYPE_P(value) <= IS_STRING && Z_TYPE_P(value) != IS_UNDEF) {
        char head[3 + VARINT_MAX_BYTES];
        size_t head_len = 0, payload_len = 0;
        const char *payload = NULL;
        if (enc_scalar_head(value, head, &head_len, &payload, &payload_len)) {
            size_t frame_len = head_len + payload_len;
            if (EXPECTED(tail_reserve <= SIZE_MAX - 4096
                         && frame_len <= SIZE_MAX - 4096 - tail_reserve)) {
                if (raw_mode) {
                    unsigned char *raw = (unsigned char *)emalloc(frame_len + tail_reserve + 1);
                    memcpy(raw, head, head_len);
                    if (payload_len) memcpy(raw + head_len, payload, payload_len);
                    raw[frame_len] = '\0';
                    if (UNEXPECTED(PHPSER_HAS_OOM())) {
                        efree(raw);
                        if (status) *status = PHPSER_ENC_EXCEPTION;
                        return NULL;
                    }
                    *out_len = frame_len;
                    return raw;
                }
                zend_string *res = zend_string_alloc(frame_len + tail_reserve, 0);
                memcpy(ZSTR_VAL(res), head, head_len);
                if (payload_len) memcpy(ZSTR_VAL(res) + head_len, payload, payload_len);
                ZSTR_VAL(res)[frame_len] = '\0';
                ZSTR_LEN(res) = frame_len;
                if (UNEXPECTED(PHPSER_HAS_OOM())) {
                    zend_string_release(res);
                    if (status) *status = PHPSER_ENC_EXCEPTION;
                    return NULL;
                }
                return res;
            }
        }
    }

    return phpser_encode_container_core(
        value, throw_on_overflow, status, tail_reserve, raw_mode, out_len);
}

zend_string *phpser_encode_zval_ex(zval *value, bool throw_on_overflow,
                                   phpser_enc_status *status, size_t tail_reserve) {
    return (zend_string *)phpser_encode_zval_core(
        value, throw_on_overflow, status, tail_reserve, false, NULL);
}

unsigned char *phpser_encode_zval_raw(zval *value, bool throw_on_overflow,
                                      phpser_enc_status *status, size_t tail_reserve,
                                      size_t *out_len) {
    return (unsigned char *)phpser_encode_zval_core(
        value, throw_on_overflow, status, tail_reserve, true, out_len);
}

zend_string *phpser_encode_zval(zval *value, bool throw_on_overflow) {
    return phpser_encode_zval_ex(value, throw_on_overflow, NULL, 0);
}

/* Fast path for top-level scalars (ndict == 0): mirrors enc_scalar_frame,
 * skipping decode_ctx initialization and teardown. Returns 1 if decoded,
 * 0 to fall back to the full decoder, -1 on framing/OOM error. */
static int dec_scalar_frame(const char *str, size_t str_len, zval *out, bool require_exact) {
    if (str_len < 3) return 0;
    const uint8_t *buf = (const uint8_t *)str;
    uint8_t ver = buf[0];
    if (ver != PHPSER_VERSION && ver != PHPSER_VERSION_V2) return 0;
    if (buf[1] != 0) return 0;
    size_t pos = 3;
    uint8_t tag = buf[2];
    if (tag <= TAG_TRUE) {
        if ((require_exact && UNEXPECTED(pos != str_len)) || UNEXPECTED(PHPSER_HAS_OOM())) {
            return -1;
        }
        Z_TYPE_INFO_P(out) = (uint32_t)tag + 1u;
        return 1;
    }
    switch (tag) {
        case TAG_LONG: {
            int64_t v;
            if (varint_read_i64(buf, str_len, &pos, &v) < 0
                || UNEXPECTED(!dec_i64_fits_zend_long(v))
                || (require_exact && UNEXPECTED(pos != str_len))
                || UNEXPECTED(PHPSER_HAS_OOM())) {
                return -1;
            }
            ZVAL_LONG(out, (zend_long)v);
            return 1;
        }
        case TAG_DOUBLE:
            if (pos + 8 > str_len
                || (require_exact && UNEXPECTED(pos + 8 != str_len))
                || UNEXPECTED(PHPSER_HAS_OOM())) {
                return -1;
            }
            ZVAL_DOUBLE(out, le64_read(buf + pos));
            return 1;
        case TAG_STR_INLINE: {
            uint64_t slen;
            if (varint_read_u64(buf, str_len, &pos, &slen) < 0
                || slen > UINT32_MAX || slen > str_len - pos
                || (require_exact && UNEXPECTED(pos + (size_t)slen != str_len))
                || UNEXPECTED(PHPSER_HAS_OOM())) {
                return -1;
            }
            zend_string *zs = dec_string_init((const char *)buf + pos, (size_t)slen);
            if (UNEXPECTED(!zs)) return -1;
            if (UNEXPECTED(PHPSER_HAS_OOM())) {
                zend_string_release(zs);
                return -1;
            }
            ZVAL_STR(out, zs);
            return 1;
        }
        default:
            return 0;
    }
}

int phpser_decode_buf_opts(
    const char *str, size_t str_len, zval *out,
    int allowed_mode, HashTable *allowed_set, bool require_exact)
{
    int s_rc = dec_scalar_frame(str, str_len, out, require_exact);
    if (s_rc != 0) {
        if (UNEXPECTED(s_rc < 0)) {
            ZVAL_NULL(out);
            return -1;
        }
        return 0;
    }

#ifdef ZEND_CHECK_STACK_LIMIT
    if (UNEXPECTED(zend_call_stack_overflowed(EG(stack_limit)))) {
        ZVAL_NULL(out);
        return -1;
    }
#endif

    /* Outside decode_ctx so the zero-initializer stays small. */
    id_slot id_inline[DEC_ID_INLINE_CAP];
    zend_string *dict_inline[DEC_DICT_INLINE_CAP];
    dec_class_slot class_inline[DEC_CLASS_INLINE_CAP];
    decode_ctx d = {0};
    d.buf = (const uint8_t *)str;
    d.len = str_len;
    d.allowed_mode = allowed_mode;
    d.allowed_set = allowed_set;
    d.id_table = id_inline;
    d.id_table_cap = DEC_ID_INLINE_CAP;
    d.dict_inline = dict_inline;
    d.class_slots_inline = class_inline;
    d.materialize_left = str_len > (SIZE_MAX - PHPSER_MATERIALIZE_ALLOWANCE)
            / PHPSER_MATERIALIZE_RATIO
        ? SIZE_MAX
        : PHPSER_MATERIALIZE_ALLOWANCE + str_len * PHPSER_MATERIALIZE_RATIO;
    d.compare_left = str_len > (SIZE_MAX - 65536) / 64
        ? SIZE_MAX : 65536 + str_len * 64;

    if (decode_header(&d) < 0) {
        decode_destroy(&d, false);
        ZVAL_NULL(out);
        return -1;
    }
    if (decode_value(&d, out) < 0) {
        zval_ptr_dtor(out);
        ZVAL_NULL(out);
        decode_destroy(&d, false);
        return -1;
    }
    /* Signed and session frames require exact consumption before hooks run. The
     * unsigned entry point retains historical trailing-byte tolerance. */
    if ((require_exact && UNEXPECTED(d.pos != d.len)) || PHPSER_HAS_OOM()) {
        zval_ptr_dtor(out);
        ZVAL_NULL(out);
        decode_destroy(&d, false);
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
        if (EXPECTED(!EG(exception))) {
            GC_DEL_FLAGS(obj, IS_OBJ_DESTRUCTOR_CALLED);
        }
        zval_ptr_dtor(&retval);
        if (UNEXPECTED(EG(exception) || PHPSER_HAS_OOM())) goto done;
    }
done:;
    /* Hooks run user code that can detach pinned entities from the result.
     * Without pins there is nothing to release, so skip the checks. */
    bool result_live = false;
    if (d.id_table_len) {
        result_live = !d.pins_may_orphan && d.deferred_len == 0
                      && !EG(exception) && !PHPSER_HAS_OOM();
    }
    /* Only objects and references can close a cycle, and each claims an id.
     * Without one, the fast release skipped nothing that needs a root. */
    bool root_result = result_live && d.ids_may_cycle;
    decode_destroy(&d, result_live);
    /* The PHP entry point is not declared by-reference, so unwrap a top-level
     * TAG_NEW_REF before returning to Zend. The pin was released above while
     * `out` still held the reference; this drop takes the normal root path. */
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
    if (UNEXPECTED(EG(exception) || PHPSER_HAS_OOM())) {
        zval_ptr_dtor(out);
        ZVAL_NULL(out);
        return -1;
    }
    /* Zend frees discarded temporaries, such as an unused user-function return
     * value, with zval_ptr_dtor_nogc. That skips root buffering, so a cyclic
     * graph whose pins released without a root would leak until shutdown.
     * Every decoded entity is reachable from `out`, so one root covers the
     * graph, as in native unserialize and igbinary. The slow release already
     * buffered each surviving entity. */
    if (root_result && Z_REFCOUNTED_P(out)) {
        gc_check_possible_root(Z_COUNTED_P(out));
    }
    return 0;
}
