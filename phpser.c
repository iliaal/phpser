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

/*
 * phpser — a PHP serialization extension targeting read-heavy cache workloads.
 *
 * Design notes (full discussion in README.md):
 *   - Front-loaded string dictionary; values reference by varint index.
 *   - Pointer-equality intern hits on encode (PHP interns common literals).
 *   - Refcount-reuse of zend_strings on decode via a per-payload cache.
 *   - Packed-array fast path: PACKED_LONGS / PACKED_DOUBLES are typed runs
 *     that decode into zend_new_array(N) + direct arPacked writes.
 *   - Sparse-packed (post-unset) arrays preserve original int keys via Assoc.
 */
#include "php.h"
#include "php_phpser.h"
#include "ext/standard/info.h"
#include "Zend/zend_API.h"
#include "Zend/zend_smart_str.h"
#include "Zend/zend_hash.h"

#include "Zend/zend_enum.h"
#include "Zend/zend_exceptions.h"
#include "Zend/zend_execute.h"          /* zend_verify_prop_assignable_by_ref */
#include "Zend/zend_objects_API.h"      /* zend_get_typed_property_info_for_slot */
#include "ext/standard/php_incomplete_class.h"
#include "ext/hash/php_hash.h"

#ifdef HAVE_PHP_SESSION
# include "ext/session/php_session.h"
#endif

#define PHPSER_HMAC_TAG_LEN 32  /* SHA256 output size */

#include <stdint.h>
#include <string.h>

/* Wire format version. Bump on any incompatible change. */
#define PHPSER_VERSION 0x01

/* Value tags. */
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
#define TAG_PACKED_STRINGS  0x0b   /* varint(len), N×varint(dict_idx) — typed string run */
#define TAG_STR_INLINE      0x0c   /* varint(len), bytes — single-use string, skips dict */
#define TAG_ENUM            0x0d   /* varint(class_idx), varint(case_name_idx) */
#define TAG_OBJECT_MAGIC    0x0e   /* varint(class_idx), value — class with __serialize/__unserialize.
                                      The value is whatever __serialize() returned (always an array,
                                      enforced by PHP). On decode we instantiate + call __unserialize. */
#define TAG_OBJECT_LEGACY   0x0f   /* varint(class_idx), varint(len), bytes — class with the C-level
                                      ce->serialize / ce->unserialize hook (Serializable interface and
                                      old SPL classes that haven't migrated to __serialize). The bytes
                                      are opaque output from ce->serialize. */
#define TAG_REF             0x10   /* varint(id) — back-reference to a previously-emitted container.
                                      id counts in encounter order on both sides; tags TAG_OBJECT,
                                      TAG_OBJECT_MAGIC, TAG_OBJECT_LEGACY, TAG_ENUM, and TAG_NEW_REF
                                      each implicitly claim the next id. */
#define TAG_NEW_REF         0x11   /* inner value follows — claims the next id for an IS_REFERENCE wrap.
                                      On decode we allocate a fresh zend_reference, register it in the
                                      id table, then decode the inner value into ref->val. */

/* Assoc key tags (one byte before the key's payload). */
#define KEY_LONG        0x00
#define KEY_STR         0x01   /* varint(dict_idx) */
#define KEY_STR_INLINE  0x02   /* varint(len), bytes */

/* -------------------------------------------------------------------------
 * Varint helpers. Unsigned varint (LEB128) for lengths/indices, zigzag for
 * signed longs. Reader returns 0 on success, -1 on truncation.
 * ------------------------------------------------------------------------- */

static inline void varint_write_u64(smart_str *s, uint64_t v) {
    while (v >= 0x80) {
        smart_str_appendc(s, (char)((v & 0x7f) | 0x80));
        v >>= 7;
    }
    smart_str_appendc(s, (char)v);
}

/* Common case: tag byte + small varint. Inlines two appendc for the typical
 * (tag + 1-byte varint) shape, which is the dominant pattern for assoc key
 * indices and string-dict refs (dicts rarely exceed 128 entries per payload). */
static inline void emit_tag_and_varint(smart_str *s, uint8_t tag, uint64_t v) {
    smart_str_appendc(s, (char)tag);
    varint_write_u64(s, v);
}

static inline int varint_read_u64(const uint8_t *buf, size_t buflen, size_t *pos, uint64_t *out) {
    /* Fast path: 1-byte varint (value < 128). The vast majority of varints
     * in rowset-shaped payloads are dict_idx / key_idx values < 128, plus
     * small assoc counts and key/value lengths. Inlining this case saves
     * the loop init + bounds re-check that the multi-byte path needs. */
    size_t p = *pos;
    if (UNEXPECTED(p >= buflen)) return -1;
    uint8_t b = buf[p];
    if (EXPECTED((b & 0x80) == 0)) {
        *out = b;
        *pos = p + 1;
        return 0;
    }
    /* Slow path: 2+ byte varint. */
    uint64_t v = b & 0x7f;
    p++;
    int shift = 7;
    while (p < buflen) {
        b = buf[p++];
        if (shift >= 64) return -1;
        uint64_t chunk = b & 0x7f;
        /* Overflow guard for the 10th byte. uint64_t holds 64 bits; at
         * shift=63 only bit 0 of `chunk` is in range. Bits 1-6 of the
         * 10th byte would shift past bit 64 and silently wrap modulo
         * 2^64, so a crafted payload encoding 2^64 (0x80*9 + 0x02)
         * decodes as 0 — aliasing dict_idx 0 from the attacker's perspective.
         * Reject any non-zero high bits on the final byte. */
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

/* Wire format pins doubles as 8-byte little-endian IEEE754. On x86/ARM
 * host order already matches LE; both helpers degenerate to the same
 * memcpy the original code did, with zero overhead. On a hypothetical
 * big-endian host (POWER BE, S390x) we byte-reverse to keep the wire
 * format portable per the README spec.
 *
 * smart_str_append_le64 writes directly through smart_str_appendl so we
 * don't pay an intermediate stack-buffer memcpy on the LE fast path. */
static inline void smart_str_append_le64(smart_str *s, double v) {
#ifdef WORDS_BIGENDIAN
    uint64_t bits;
    memcpy(&bits, &v, 8);
    char buf[8];
    for (int i = 0; i < 8; i++) buf[i] = (char)((bits >> (i * 8)) & 0xff);
    smart_str_appendl(s, buf, 8);
#else
    smart_str_appendl(s, (char *)&v, 8);
#endif
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

/* -------------------------------------------------------------------------
 * Encode state. Two-tier intern:
 *   - inline_cache: 16-slot ring of (zend_string*, dict_idx). Linear-scan
 *     pointer-equality probe; near-zero cost on hits when PHP literals
 *     share interned zend_string allocations across rows.
 *   - hash_map: HashTable keyed by zend_string content. Consulted on
 *     inline-cache miss once dict_len crosses HASH_MAP_THRESHOLD. Catches
 *     both "same content, different allocation" (runtime-built strings
 *     equaling a literal) AND "cache slot evicted, dict entry still
 *     present" — the dominant case on rowset workloads, where row_X
 *     allocations push prop-key slots out of the 16-slot cache after
 *     ~16 rows.
 *   - dict: index→zend_string* array we emit at the head.
 * ------------------------------------------------------------------------- */

#define INTERN_CACHE_SIZE 16

/* Threshold under which we skip the hash_map check on miss and just emit
 * the string inline (potentially duplicating bytes for a key already in
 * the dict). The 16-slot intern cache is FIFO-evicted, so on rowset-shape
 * payloads with many unique string values (e.g. 1000 row_X) the prop-key
 * cache slots get evicted after ~16 inserts. Without hash_map fallback,
 * the next encounter of the prop key would re-emit it inline, bloating
 * the wire format.
 *
 * Lowered from 32 to 4: above 4 dict entries the hash_map lookup cost
 * (~15 cycles) is comfortably less than the inline-emit cost (~30 cycles
 * + wasted wire bytes). Below 4, the cache rarely misses anyway. */
#define HASH_MAP_THRESHOLD 4

/* Slot kinds in the intern cache, encoded in the high bit of idx.
 *
 *   INLINE_EMITTED: we've seen this zend_string once and emitted its bytes
 *                   inline. If we see it again, that's the signal to
 *                   upgrade to a dict entry — the inline emission has
 *                   already happened, future occurrences become refs.
 *   DICT_IDX:       string lives in the dict at the recorded idx. All
 *                   occurrences from here on emit a dict ref.
 *
 * Stuffing the kind into idx's high bit keeps intern_slot at 16 bytes
 * (1 cache line stride friendly) and lets the cache scan stay a tight
 * pointer-equality loop. INLINE_EMITTED slots use idx as a sentinel — we
 * never read the idx for those, just check the kind. */
#define SLOT_KIND_BIT       0x80000000u
#define SLOT_IS_DICT(s)     (((s).idx & SLOT_KIND_BIT) == 0)
#define SLOT_DICT_IDX(s)    ((s).idx)
#define SLOT_INLINE_MARK    SLOT_KIND_BIT  /* sentinel idx for inline-only slots */

typedef struct {
    zend_string *ptr;
    uint32_t idx;  /* high bit set = INLINE_EMITTED, clear = DICT_IDX with idx in low 31 bits */
} intern_slot;

/* Cycle guard for recursive encode/decode. Cache payloads usually nest
 * 5-10 deep; anything beyond MAX_DEPTH is treated as a runaway and
 * aborted. The most common way to hit this is IS_REFERENCE pointing back
 * into an ancestor: we flatten references rather than encode them as
 * shareable, so a true self-ref turns into an infinite chase without
 * this counter.
 *
 * 512 picked to stay safely below stack-overflow on every supported
 * build: ASAN-instrumented decode_value frames can hit ~1.5 KB each
 * (vs ~150 B for opt-NTS), so the cap must hold within a single 8 MB
 * stack worst-case. 512 leaves ~2x headroom under ASAN and ~50x under
 * opt-NTS, and is still many orders of magnitude past any legitimate
 * cache payload. */
#define MAX_DEPTH 512

/* Flat open-addressed identity table: (ptr → id). Linear probing on a
 * power-of-2 sized bucket array; empty slot is ptr==0. Way tighter than
 * the general zend_hash_index_* in the hot path — ~3-5 instructions per
 * probe vs ~25 for the HT. Heap-allocated lazily on first visit so
 * payloads with no objects/refs pay nothing. */
typedef struct {
    uintptr_t ptr;   /* 0 = empty */
    uint32_t  id;
} id_entry;

typedef struct {
    intern_slot inline_cache[INTERN_CACHE_SIZE];
    uint32_t cache_filled;
    uint32_t cache_next;
    HashTable hash_map;
    uint8_t   hash_map_inited;   /* lazy init: skipped for small payloads */
    /* Per-payload id table for (zend_object*|zend_reference*) → uint32 id
     * assigned in encounter order. First visit emits the usual container
     * tag (claiming the next id implicitly); subsequent visits emit
     * TAG_REF + id. Preserves object handle identity (PHP's `r:N`) and
     * IS_REFERENCE sharing (`R:N`), and terminates cycles without
     * losing back-edges. */
    id_entry *id_buckets;
    uint32_t id_mask;       /* capacity - 1, capacity is power of 2; 0 means unallocated */
    uint32_t id_count;
    uint32_t next_id;
    zend_string **dict;
    uint32_t dict_len;
    uint32_t dict_cap;
    uint32_t depth;
    /* Set when encode_value hits MAX_DEPTH and substitutes TAG_NULL. The
     * substitution keeps the in-progress buffer well-formed, but the result
     * would silently lose data (and decode rejects it at the same cap), so
     * phpser_encode_zval checks this after the walk and fails loudly rather
     * than handing back a lossy payload. */
    uint8_t depth_exceeded;
} encode_ctx;

static void enc_ctx_init(encode_ctx *e) {
    memset(e->inline_cache, 0, sizeof(e->inline_cache));
    e->cache_filled = 0;
    e->cache_next = 0;
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
}

static void enc_ctx_destroy(encode_ctx *e) {
    if (e->hash_map_inited) zend_hash_destroy(&e->hash_map);
    if (e->id_buckets) efree(e->id_buckets);
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

/* Returns 1 if this is the first time we've seen `ptr`, with the assigned
 * id written into *out_id (decoder doesn't need it on first-visit since the
 * wire format claims it implicitly via encounter order, but it's useful for
 * debugging / sanity asserts).
 *
 * Returns 0 if `ptr` is a repeat; *out_id holds its previously-assigned id. */
static inline int enc_visit(encode_ctx *e, void *ptr, uint32_t *out_id) {
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
    e->id_count++;
    *out_id = id;
    return 1;
}

/* Roll back the most-recent enc_visit-claimed id. Used by the encode
 * paths that call enc_visit speculatively (deciding to emit a TAG_NULL
 * after __serialize/__sleep returns non-array, or ce->serialize returns
 * FAILURE). Without rollback, the encoder claims id N but emits TAG_NULL
 * — the decoder never registers id N — and the next id-claiming tag on
 * the encoder side becomes id N+1 while the decoder thinks it's id N.
 * Subsequent TAG_REF references then deref the wrong slot or OOB-reject.
 *
 * Safe only if no other enc_visit happened between the claim and this
 * call (the bucket at the end of the probe chain is the one we just
 * inserted into). Encoder dispatch holds this invariant: __serialize /
 * __sleep / ce->serialize run user PHP but that user code can't reach
 * back into our encoder's id-table. */
static inline void enc_unvisit_last(encode_ctx *e, void *ptr) {
    uintptr_t pp = (uintptr_t)ptr;
    uint32_t h = id_hash(pp) & e->id_mask;
    while (e->id_buckets[h].ptr != pp) {
        h = (h + 1) & e->id_mask;
    }
    e->id_buckets[h].ptr = 0;
    e->id_count--;
    e->next_id--;
}

static inline intern_slot *enc_cache_find(encode_ctx *e, zend_string *zs) {
    for (uint32_t i = 0; i < e->cache_filled; i++) {
        if (e->inline_cache[i].ptr == zs) return &e->inline_cache[i];
    }
    return NULL;
}

static inline intern_slot *enc_cache_alloc_slot(encode_ctx *e, zend_string *zs) {
    uint32_t slot;
    if (e->cache_filled < INTERN_CACHE_SIZE) {
        slot = e->cache_filled++;
    } else {
        /* FIFO ring eviction, but skip past slots that hold a "graduated"
         * DICT_IDX entry. On rowset workloads the prop-key slots
         * (graduated to DICT after row 2) would otherwise get evicted
         * by row_X unique-string allocations; that triggers cache misses
         * + hash_map fallback for every subsequent prop access. By
         * preferring to evict INLINE_EMITTED (singleton) slots, we keep
         * the hot DICT slots warm. Worst-case probe is INTERN_CACHE_SIZE
         * (all slots are DICT — then we evict the first one and accept
         * the cost), but typical case finds a non-DICT slot in 1-2
         * probes. */
        slot = e->cache_next;
        for (uint32_t i = 0; i < INTERN_CACHE_SIZE; i++) {
            if (!SLOT_IS_DICT(e->inline_cache[slot])) break;
            slot = (slot + 1) % INTERN_CACHE_SIZE;
        }
        e->cache_next = (slot + 1) % INTERN_CACHE_SIZE;
    }
    e->inline_cache[slot].ptr = zs;
    return &e->inline_cache[slot];
}

/* Allocate a dict slot for `zs` and return its index. Also maintains the
 * content hash_map once the dict has crossed HASH_MAP_THRESHOLD entries
 * (small dicts skip the hash work entirely — pointer-equality via cache
 * already catches the literal-interned case). */
static uint32_t enc_dict_append(encode_ctx *e, zend_string *zs) {
    if (e->dict_len == e->dict_cap) {
        e->dict_cap = e->dict_cap ? e->dict_cap * 2 : 16;
        e->dict = erealloc(e->dict, e->dict_cap * sizeof(zend_string *));
    }
    uint32_t idx = e->dict_len++;
    /* Own a refcount on the dict entry. Magic-method paths (__sleep
     * dynamic names, __serialize return arrays) borrow zend_strings
     * from temporaries we dtor mid-encode; without this addref the dict
     * pointer dangles by the time the header is emitted, and reads land
     * on freed allocator memory. Interned literals (the common case)
     * short-circuit zend_string_copy to a flag-check no-op. */
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

/* Always-dict intern path: used for object class names and property keys,
 * which we don't try to inline (TAG_OBJECT wire format hardcodes dict refs
 * for those slots). */
static uint32_t enc_intern_zstr(encode_ctx *e, zend_string *zs) {
    intern_slot *s = enc_cache_find(e, zs);
    if (s && SLOT_IS_DICT(*s)) return SLOT_DICT_IDX(*s);

    /* Cache miss or INLINE_EMITTED. Try content lookup once the dict is big
     * enough to matter. */
    if (e->dict_len >= HASH_MAP_THRESHOLD) {
        zval *hit = zend_hash_find(&e->hash_map, zs);
        if (hit) {
            uint32_t idx = (uint32_t)Z_LVAL_P(hit);
            if (s) { s->idx = idx; } else { enc_cache_alloc_slot(e, zs)->idx = idx; }
            return idx;
        }
    }

    uint32_t idx = enc_dict_append(e, zs);
    if (s) { s->idx = idx; } else { enc_cache_alloc_slot(e, zs)->idx = idx; }
    return idx;
}

/* Emit a string, choosing between an inline tag (first encounter) and a
 * dict-ref tag (second+ encounter via upgrade). Single-pass: we don't know
 * if a string will repeat until we see it again. Subtle properties:
 *
 *   - First-time strings emit `inline_tag` — no dict insert cost.
 *   - On the SECOND encounter we promote to the dict; the previous inline
 *     emission stays as-is in the buffer (it's still a valid value), and
 *     all subsequent occurrences emit `dict_tag` with the assigned idx.
 *   - For pure-singleton strings (e.g. row_X values in a rowset), we never
 *     hit the upgrade branch — no dict header overhead either.
 *   - Eviction from the 16-slot ring is benign: a later re-encounter of
 *     an evicted INLINE_EMITTED string will inline-emit it a second time.
 *     Decode is still correct; the wire just has the same bytes twice.
 *
 * Values pass (TAG_STR_DICT, TAG_STR_INLINE); assoc keys pass
 * (KEY_STR, KEY_STR_INLINE). The logic is identical; only the tag bytes
 * differ, so both entry points are thin wrappers around this. */
static zend_always_inline void enc_emit_str_tagged(
    smart_str *body, encode_ctx *e, zend_string *zs,
    uint8_t dict_tag, uint8_t inline_tag)
{
    intern_slot *s = enc_cache_find(e, zs);
    if (s) {
        if (SLOT_IS_DICT(*s)) {
            emit_tag_and_varint(body, dict_tag, SLOT_DICT_IDX(*s));
            return;
        }
        /* INLINE_EMITTED — upgrade in place. */
        s->idx = enc_dict_append(e, zs);  /* writes into low 31 bits; high bit cleared */
        emit_tag_and_varint(body, dict_tag, s->idx);
        return;
    }
    /* Cache miss above HASH_MAP_THRESHOLD: content dedup might still hit. */
    if (e->dict_len >= HASH_MAP_THRESHOLD) {
        zval *hit = zend_hash_find(&e->hash_map, zs);
        if (hit) {
            uint32_t idx = (uint32_t)Z_LVAL_P(hit);
            enc_cache_alloc_slot(e, zs)->idx = idx;
            emit_tag_and_varint(body, dict_tag, idx);
            return;
        }
    }
    /* First encounter: emit inline, mark in cache as INLINE_EMITTED so the
     * next occurrence triggers the upgrade above. */
    smart_str_appendc(body, inline_tag);
    varint_write_u64(body, ZSTR_LEN(zs));
    smart_str_appendl(body, ZSTR_VAL(zs), ZSTR_LEN(zs));
    enc_cache_alloc_slot(e, zs)->idx = SLOT_INLINE_MARK;
}

static void enc_emit_str_value(smart_str *body, encode_ctx *e, zend_string *zs) {
    enc_emit_str_tagged(body, e, zs, TAG_STR_DICT, TAG_STR_INLINE);
}

static void enc_emit_str_key(smart_str *body, encode_ctx *e, zend_string *zs) {
    enc_emit_str_tagged(body, e, zs, KEY_STR, KEY_STR_INLINE);
}

/* -------------------------------------------------------------------------
 * Encode dispatch.
 * ------------------------------------------------------------------------- */

static void encode_value(smart_str *body, encode_ctx *e, zval *v);
static void encode_value_inner(smart_str *body, encode_ctx *e, zval *v);
static void encode_hashtable(smart_str *body, encode_ctx *e, HashTable *ht);

static void encode_value(smart_str *body, encode_ctx *e, zval *v) {
    /* Declared properties surface as IS_INDIRECT in get_properties() HTs —
     * the bucket holds a pointer to the real slot in properties_table[].
     * Deref before dispatching; otherwise we'd emit NULL for every typed
     * property. */
    if (Z_TYPE_P(v) == IS_INDIRECT) {
        v = Z_INDIRECT_P(v);
    }
    /* Cycle guard. References that point back into an ancestor would
     * recurse forever without this — we flatten Z_REFVAL_P, and a self-ref
     * chases the same zval indefinitely. Substitute TAG_NULL to keep the
     * buffer well-formed for the remainder of the walk, but flag the
     * truncation so the caller can reject the whole payload instead of
     * silently shipping lossy bytes (which decode rejects anyway). */
    if (UNEXPECTED(e->depth >= MAX_DEPTH)) {
        e->depth_exceeded = 1;
        smart_str_appendc(body, TAG_NULL);
        return;
    }
    e->depth++;
    encode_value_inner(body, e, v);
    e->depth--;
}

static void encode_value_inner(smart_str *body, encode_ctx *e, zval *v) {
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
        case IS_ARRAY:
            encode_hashtable(body, e, Z_ARRVAL_P(v));
            return;
        case IS_REFERENCE: {
            /* zend_reference identity: if we've seen this exact zend_reference
             * struct, emit a back-ref. Otherwise claim a new id and emit
             * TAG_NEW_REF + inner value. */
            zend_reference *ref = Z_REF_P(v);
            uint32_t id;
            if (!enc_visit(e, ref, &id)) {
                emit_tag_and_varint(body, TAG_REF, id);
                return;
            }
            smart_str_appendc(body, TAG_NEW_REF);
            /* id is implicitly id == enc_visit's assigned id; decoder will
             * register the new zend_reference at next_id++ in encounter order
             * BEFORE recursing into the inner value (so a back-ref inside
             * the inner can resolve to this very reference). */
            encode_value(body, e, Z_REFVAL_P(v));
            return;
        }
        case IS_OBJECT: {
            zend_object *obj = Z_OBJ_P(v);
            /* Classes marked NOT_SERIALIZABLE (Closure, Generator, internal
             * resources etc.) can't be reconstructed by object_init_ex —
             * their create_object handlers reject external instantiation.
             * Emit NULL like PHP's serialize() does (modulo the userland
             * error PHP raises that we don't expose yet). */
            if (obj->ce->ce_flags & ZEND_ACC_NOT_SERIALIZABLE) {
                smart_str_appendc(body, TAG_NULL);
                return;
            }
            /* Object handle identity: PHP's `r:N` semantics. If we've seen
             * this exact zend_object before, emit a back-ref. Otherwise
             * claim a new id and let the chosen container tag below take
             * it implicitly via encounter order. */
            {
                uint32_t id;
                if (!enc_visit(e, obj, &id)) {
                    emit_tag_and_varint(body, TAG_REF, id);
                    return;
                }
            }
            /* Legacy C-level serializer (Serializable interface or built-in
             * SPL classes that haven't migrated to __serialize). PHP checks
             * __serialize FIRST and falls through to this if it's absent.
             * SplPriorityQueue, SplMinHeap, SplMaxHeap, SplFileInfo all
             * land here. */
            if (obj->ce->__serialize == NULL && obj->ce->serialize != NULL) {
                unsigned char *data = NULL;
                size_t len = 0;
                /* var_hash is NULL — we don't share its ref-tracking state
                 * with PHP's serialize() pipeline, which means SPL serializers
                 * that record back-refs internally get a fresh slate. For
                 * isolated cache values this is fine; for payloads with
                 * shared subobjects across the boundary, the dedup gets
                 * dropped but correctness holds. */
                if (obj->ce->serialize(v, &data, &len, NULL) != SUCCESS) {
                    if (data) efree(data);
                    /* Roll back the id we speculatively claimed — see
                     * enc_unvisit_last comment. Otherwise back-refs to
                     * this object later in the payload misalign. */
                    enc_unvisit_last(e, obj);
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
            /* __serialize() takes precedence over property iteration for
             * any class that defines it (PHP 7.4+). This unlocks ArrayObject,
             * SplObjectStorage, DateTime, and the rest of the SPL classes
             * that have migrated to the modern magic methods. */
            if (obj->ce->__serialize != NULL) {
                zval retval;
                ZVAL_UNDEF(&retval);
                zend_call_known_instance_method_with_0_params(
                    obj->ce->__serialize, obj, &retval);
                if (UNEXPECTED(EG(exception)) || Z_TYPE(retval) != IS_ARRAY) {
                    /* Match PHP's behavior: type error / exception during
                     * __serialize → emit NULL and bail. Don't swallow the
                     * exception silently — PHP propagates it. Roll back
                     * the speculative id-claim so back-refs to this
                     * object later in the payload don't misalign. */
                    zval_ptr_dtor(&retval);
                    enc_unvisit_last(e, obj);
                    smart_str_appendc(body, TAG_NULL);
                    return;
                }
                uint32_t class_idx = enc_intern_zstr(e, obj->ce->name);
                emit_tag_and_varint(body, TAG_OBJECT_MAGIC, class_idx);
                encode_value(body, e, &retval);
                zval_ptr_dtor(&retval);
                return;
            }
            /* Enums are class-controlled singletons — object_init_ex won't
             * recreate them on the decode side. Emit class + case name so
             * we can resolve via zend_enum_get_case during decode. */
            if (obj->ce->ce_flags & ZEND_ACC_ENUM) {
                uint32_t class_idx = enc_intern_zstr(e, obj->ce->name);
                zval *cname = zend_enum_fetch_case_name(obj);
                uint32_t case_idx = enc_intern_zstr(e, Z_STR_P(cname));
                emit_tag_and_varint(body, TAG_ENUM, class_idx);
                varint_write_u64(body, case_idx);
                return;
            }
            uint32_t class_idx = enc_intern_zstr(e, obj->ce->name);
            /* __sleep: if defined, the method returns an array of property
             * names to serialize. Unknown / static / IS_UNDEF entries are
             * skipped (matches PHP behavior modulo the warnings PHP emits
             * that we don't propagate). For declared props, ce->properties_info
             * gives us the mangled name + offset; for dynamic props, fall
             * back to obj->properties. Output stays TAG_OBJECT — the decode
             * side doesn't need to know __sleep ran.
             *
             * Lookup pattern mirrors ext/standard/var.c:1216 — __sleep is not
             * a struct member of zend_class_entry; PHP keeps it as a regular
             * function and finds it via the function_table on demand. */
            zval *sleep_fn_zv = zend_hash_find_known_hash(
                &obj->ce->function_table, ZSTR_KNOWN(ZEND_STR_SLEEP));
            if (sleep_fn_zv != NULL) {
                zval names_zv;
                ZVAL_UNDEF(&names_zv);
                zend_call_known_instance_method_with_0_params(
                    Z_FUNC_P(sleep_fn_zv), obj, &names_zv);
                if (UNEXPECTED(EG(exception)) || Z_TYPE(names_zv) != IS_ARRAY) {
                    /* Same id-rollback as the __serialize failure path. */
                    zval_ptr_dtor(&names_zv);
                    enc_unvisit_last(e, obj);
                    smart_str_appendc(body, TAG_NULL);
                    return;
                }
                HashTable *names_ht = Z_ARRVAL(names_zv);
                /* Two passes: count, then emit. Hash lookups are O(1) so
                 * the extra walk is cheap; we need an accurate nprops for
                 * the wire format ahead of the bucket emission. */
                uint32_t nprops = 0;
                zval *zv_name;
                ZEND_HASH_FOREACH_VAL(names_ht, zv_name) {
                    if (Z_TYPE_P(zv_name) != IS_STRING) continue;
                    zend_string *nm = Z_STR_P(zv_name);
                    zend_property_info *info = zend_hash_find_ptr(&obj->ce->properties_info, nm);
                    if (info != NULL) {
                        if (info->flags & ZEND_ACC_STATIC) continue;
#if PHP_VERSION_ID >= 80400
                        if (info->offset == (uint32_t)ZEND_VIRTUAL_PROPERTY_OFFSET) continue;
#endif
                        zval *p = OBJ_PROP(obj, info->offset);
                        if (Z_TYPE_P(p) == IS_UNDEF) continue;
                        nprops++;
                    } else if (obj->properties) {
                        zval *p = zend_hash_find(obj->properties, nm);
                        if (p && Z_TYPE_P(p) != IS_UNDEF) nprops++;
                    }
                } ZEND_HASH_FOREACH_END();
                smart_str_appendc(body, TAG_OBJECT);
                varint_write_u64(body, class_idx);
                varint_write_u64(body, nprops);
                ZEND_HASH_FOREACH_VAL(names_ht, zv_name) {
                    if (Z_TYPE_P(zv_name) != IS_STRING) continue;
                    zend_string *nm = Z_STR_P(zv_name);
                    zend_property_info *info = zend_hash_find_ptr(&obj->ce->properties_info, nm);
                    zend_string *key;
                    zval *p;
                    if (info != NULL) {
                        if (info->flags & ZEND_ACC_STATIC) continue;
#if PHP_VERSION_ID >= 80400
                        if (info->offset == (uint32_t)ZEND_VIRTUAL_PROPERTY_OFFSET) continue;
#endif
                        p = OBJ_PROP(obj, info->offset);
                        if (Z_TYPE_P(p) == IS_UNDEF) continue;
                        key = info->name;
                    } else if (obj->properties && (p = zend_hash_find(obj->properties, nm)) != NULL
                               && Z_TYPE_P(p) != IS_UNDEF) {
                        key = nm;
                    } else {
                        continue;
                    }
                    varint_write_u64(body, enc_intern_zstr(e, key));
                    encode_value(body, e, p);
                } ZEND_HASH_FOREACH_END();
                zval_ptr_dtor(&names_zv);
                return;
            }
            HashTable *props = obj->handlers->get_properties(obj);
            /* Count live entries — get_properties can include $this-style
             * entries we want to skip and tombstones (IS_UNDEF). Declared
             * props surface as IS_INDIRECT; we must deref to detect the
             * uninitialized-typed-property case (slot is IS_UNDEF but the
             * bucket val is IS_INDIRECT, not IS_UNDEF directly). Without
             * the deref, encoder emits TAG_NULL and the decoder's typed-
             * prop check rejects it as "cannot assign null to int". */
            uint32_t nprops = 0;
            if (props) {
                Bucket *b = props->arData;
                Bucket *end = b + props->nNumUsed;
                for (; b < end; b++) {
                    if (!b->key) continue;
                    zval *v = &b->val;
                    if (Z_TYPE_P(v) == IS_INDIRECT) v = Z_INDIRECT_P(v);
                    if (Z_TYPE_P(v) == IS_UNDEF) continue;
                    nprops++;
                }
            }
            smart_str_appendc(body, TAG_OBJECT);
            varint_write_u64(body, class_idx);
            varint_write_u64(body, nprops);
            if (props) {
                Bucket *b = props->arData;
                Bucket *end = b + props->nNumUsed;
                for (; b < end; b++) {
                    if (!b->key) continue;
                    zval *v = &b->val;
                    if (Z_TYPE_P(v) == IS_INDIRECT) v = Z_INDIRECT_P(v);
                    if (Z_TYPE_P(v) == IS_UNDEF) continue;
                    varint_write_u64(body, enc_intern_zstr(e, b->key));
                    encode_value(body, e, v);
                }
            }
            return;
        }
        default:
            /* Resource etc. — emit null. */
            smart_str_appendc(body, TAG_NULL);
            return;
    }
}

/* Homogeneity scan over a dense packed array. Determines whether we can use
 * a typed-run tag (PACKED_LONGS / PACKED_DOUBLES / PACKED_STRINGS) that skips
 * per-element tag emission and lets the decoder use a single tight loop.
 *
 * For PACKED_STRINGS we additionally require every element to already be
 * dict-bound — otherwise we'd lose the inline-singleton optimization. The
 * common rowset case where the same `['a','b','c']` tags array appears in
 * every row hits this fast path from row 2 onward (after the upgrade
 * during row 1's PACKED_MIXED traversal). */
static uint8_t detect_packed_run(encode_ctx *e, HashTable *ht, uint32_t n_used) {
    if (n_used == 0) return TAG_PACKED_MIXED;
    zval *zp = ht->arPacked;
    uint8_t first = Z_TYPE(zp[0]);
    if (first != IS_LONG && first != IS_DOUBLE && first != IS_STRING) {
        return TAG_PACKED_MIXED;
    }
    for (uint32_t i = 1; i < n_used; i++) {
        if (Z_TYPE(zp[i]) != first) return TAG_PACKED_MIXED;
    }
    switch (first) {
        case IS_LONG:   return TAG_PACKED_LONGS;
        case IS_DOUBLE: return TAG_PACKED_DOUBLES;
        case IS_STRING: {
            for (uint32_t i = 0; i < n_used; i++) {
                intern_slot *s = enc_cache_find(e, Z_STR(zp[i]));
                if (!s || !SLOT_IS_DICT(*s)) return TAG_PACKED_MIXED;
            }
            return TAG_PACKED_STRINGS;
        }
        default:        return TAG_PACKED_MIXED; /* unreachable */
    }
}

static void encode_hashtable(smart_str *body, encode_ctx *e, HashTable *ht) {
    uint32_t n_used = ht->nNumUsed;
    uint32_t n_elems = ht->nNumOfElements;
    int is_packed = HT_IS_PACKED(ht);

    if (is_packed && n_used == n_elems && n_used > 0) {
        /* Dense packed — try to use a typed-run tag. */
        uint8_t tag = detect_packed_run(e, ht, n_used);
        smart_str_appendc(body, tag);
        varint_write_u64(body, n_elems);
        zval *zp = ht->arPacked;
        if (tag == TAG_PACKED_LONGS) {
            for (uint32_t i = 0; i < n_used; i++) {
                varint_write_i64(body, Z_LVAL(zp[i]));
            }
        } else if (tag == TAG_PACKED_DOUBLES) {
            for (uint32_t i = 0; i < n_used; i++) {
                smart_str_append_le64(body, Z_DVAL(zp[i]));
            }
        } else if (tag == TAG_PACKED_STRINGS) {
            for (uint32_t i = 0; i < n_used; i++) {
                varint_write_u64(body, enc_intern_zstr(e, Z_STR(zp[i])));
            }
        } else {
            for (uint32_t i = 0; i < n_used; i++) {
                encode_value(body, e, &zp[i]);
            }
        }
        return;
    }

    if (is_packed) {
        /* Sparse packed (post-unset). Preserve original int keys. */
        smart_str_appendc(body, TAG_ASSOC);
        varint_write_u64(body, n_elems);
        zval *zp = ht->arPacked;
        for (uint32_t i = 0; i < n_used; i++) {
            if (Z_TYPE(zp[i]) == IS_UNDEF) continue;
            smart_str_appendc(body, KEY_LONG);
            varint_write_i64(body, (int64_t)i);
            encode_value(body, e, &zp[i]);
        }
        return;
    }

    /* Non-packed (assoc). */
    smart_str_appendc(body, TAG_ASSOC);
    varint_write_u64(body, n_elems);
    Bucket *b = ht->arData;
    Bucket *end = b + n_used;
    for (; b < end; b++) {
        if (Z_TYPE(b->val) == IS_UNDEF) continue;
        if (b->key) {
            enc_emit_str_key(body, e, b->key);
        } else {
            emit_tag_and_varint(body, KEY_LONG, zigzag_encode64((int64_t)b->h));
        }
        encode_value(body, e, &b->val);
    }
}

/* -------------------------------------------------------------------------
 * Decode state. The string cache holds one refcount per touched dict slot
 * for the lifetime of the decode call; consumers (zvals, HT entries) take
 * their own via addref. On finalize we release ours, so unreferenced
 * strings get freed and ones that landed in zvals stay alive.
 * ------------------------------------------------------------------------- */

/* A single deferred __unserialize call: we've materialized the object and
 * decoded the data array, but the call itself is delayed until the rest of
 * the tree finishes decoding. This ensures back-refs inside the array see
 * the in-progress (empty) object, and lets __unserialize run with a fully-
 * stitched object graph. PHP's var_unserializer uses the same pattern. */
typedef struct {
    zend_object *obj;
    zval data;
} deferred_unserialize;

/* Entry kind discriminates how a back-ref reconstitutes the zval. We can't
 * store a zval pointer (the slot location isn't stable across HT growth),
 * and we don't want to bump refcount per registration in the no-sharing
 * case. So we store the bare GC entity + a kind tag, and addref only when
 * a back-ref actually hits. The entity stays alive via its primary owning
 * slot for the duration of the decode pass. */
enum { ID_OBJ, ID_REF, ID_NULL };

typedef struct {
    uint8_t kind;
    union {
        zend_object    *obj;
        zend_reference *ref;
    } u;
} id_slot;

typedef struct {
    const uint8_t *buf;
    size_t len;
    size_t pos;
    zend_string **dict;        /* eagerly allocated zend_strings per slot */
    uint32_t dict_len;
    /* id_table maps encounter-order id → primary GC entity. Holds an
     * explicit refcount per entry (CR-001 fix): without it, a crafted
     * payload with a duplicate assoc/property key can let zend_hash_update
     * destroy the only bucket holding the entity while id_table still
     * points at it, and a later TAG_REF deref's freed memory. The addref
     * is released by decode_destroy. */
    id_slot *id_table;
    uint32_t id_table_len;
    uint32_t id_table_cap;
    deferred_unserialize *deferred;
    uint32_t deferred_len;
    uint32_t deferred_cap;
    /* Separate __wakeup queue: TAG_OBJECT objects whose class defines
     * __wakeup get queued here and fired after the deferred __unserialize
     * loop, so the entire graph is materialized before any wakeup hook
     * runs. PHP's var_unserializer uses the same two-phase ordering. */
    zend_object **wakeup;
    uint32_t wakeup_len;
    uint32_t wakeup_cap;
    /* allowed_classes: mirrors PHP unserialize()'s 2nd-arg option.
     *   ALLOWED_ALL — no filter (default, or options['allowed_classes']===true)
     *   ALLOWED_NONE — no classes; every object decodes to __PHP_Incomplete_Class
     *   ALLOWED_SET — only classes in `allowed_set` decode normally
     * The set is keyed by lowercased class name (PHP class names are
     * case-insensitive). Owned by the caller of phpser_decode_buf. */
    int allowed_mode;
    HashTable *allowed_set;
    /* Class-entry lookup cache: (class_idx → zend_class_entry *). Sized
     * to dict_len, lazy-allocated on first object decode. Avoids
     * re-resolving the same class through the engine's class table for
     * every TAG_OBJECT/TAG_OBJECT_MAGIC/TAG_OBJECT_LEGACY/TAG_ENUM. Real
     * cache workloads serialize batches of same-typed DTOs; without
     * this cache, decoding a 1000-DTO payload calls zend_lookup_class_ex
     * 1000 times. Sentinel (zend_class_entry *)-1 marks "looked up but
     * not found" so we don't repeatedly retry missing classes. */
    zend_class_entry **ce_cache;
    /* C-stack recursion guard: TAG_NEW_REF / TAG_PACKED_MIXED / TAG_ASSOC /
     * TAG_OBJECT / TAG_OBJECT_MAGIC all recurse through decode_value.
     * Without a cap, attacker-controlled wire format can blow the pthread
     * stack at ~100K nested frames. Bracket decode_value with ++/-- and
     * reject when >= MAX_DEPTH. */
    uint32_t depth;
} decode_ctx;

enum { ALLOWED_ALL = 0, ALLOWED_NONE, ALLOWED_SET };

/* Register the GC entity at `z` (must be IS_OBJECT or IS_REFERENCE), claiming
 * the next id. Holds an explicit refcount on the entity for the lifetime of
 * the decode pass, released in decode_destroy. Without this, a crafted
 * payload with a duplicate assoc/property key whose first value is the
 * just-registered object lets zend_hash_update destroy the only bucket
 * holding the obj — id_table then dangles, and a later TAG_REF to that id
 * deref's freed memory (zend_mm_heap corruption). The addref is per-entity
 * and amortized; cost is negligible vs. the correctness guarantee at the
 * decoder's security boundary. */
static int dec_register(decode_ctx *d, zval *z) {
    if (d->id_table_len == d->id_table_cap) {
        d->id_table_cap = d->id_table_cap ? d->id_table_cap * 2 : 16;
        d->id_table = erealloc(d->id_table, d->id_table_cap * sizeof(id_slot));
    }
    id_slot *s = &d->id_table[d->id_table_len++];
    if (Z_TYPE_P(z) == IS_OBJECT) {
        s->kind = ID_OBJ;
        s->u.obj = Z_OBJ_P(z);
        GC_ADDREF(s->u.obj);
    } else if (Z_TYPE_P(z) == IS_REFERENCE) {
        s->kind = ID_REF;
        s->u.ref = Z_REF_P(z);
        GC_ADDREF(s->u.ref);
    } else {
        /* Encoder always claimed an id for this slot; we must register
         * something to keep id counts aligned. Back-refs to a NULL slot
         * yield NULL on the decode side. */
        s->kind = ID_NULL;
    }
    return 0;
}

static int dec_defer_unserialize(decode_ctx *d, zend_object *obj, zval *data) {
    if (d->deferred_len == d->deferred_cap) {
        d->deferred_cap = d->deferred_cap ? d->deferred_cap * 2 : 4;
        d->deferred = erealloc(d->deferred, d->deferred_cap * sizeof(deferred_unserialize));
    }
    /* Hold a ref while queued. The deferred __unserialize call runs user
     * PHP, which can drop references that would otherwise free `obj`. PHP's
     * COW separates the $data param so deferred[i].data is safe, but the
     * obj pointer needs explicit ownership for symmetry with the wakeup
     * queue. Released in decode_destroy. */
    GC_ADDREF(obj);
    d->deferred[d->deferred_len].obj = obj;
    ZVAL_COPY_VALUE(&d->deferred[d->deferred_len].data, data);
    d->deferred_len++;
    return 0;
}

static int dec_defer_wakeup(decode_ctx *d, zend_object *obj) {
    if (d->wakeup_len == d->wakeup_cap) {
        d->wakeup_cap = d->wakeup_cap ? d->wakeup_cap * 2 : 4;
        d->wakeup = erealloc(d->wakeup, d->wakeup_cap * sizeof(zend_object *));
    }
    /* Hold a ref while queued. An earlier wakeup hook can mutate its own
     * properties to drop the last reference to a sibling object that's
     * also queued; without this addref the next iteration deref's a freed
     * pointer (UAF). Released in decode_destroy. */
    GC_ADDREF(obj);
    d->wakeup[d->wakeup_len++] = obj;
    return 0;
}

/* Resolve a class entry from a dict-indexed class name with memoization
 * keyed by class_idx. Sentinel (zend_class_entry *)-1 marks "tried,
 * not found" so we don't re-call zend_lookup_class_ex for missing
 * classes either. Caller still gets NULL for unknown classes. */
#define DEC_CE_MISSING ((zend_class_entry *)(uintptr_t)-1)
static inline zend_class_entry *dec_class_resolve(
    decode_ctx *d, uint64_t class_idx, zend_string *class_name)
{
    if (UNEXPECTED(!d->ce_cache)) {
        d->ce_cache = ecalloc(d->dict_len, sizeof(zend_class_entry *));
    }
    zend_class_entry *ce = d->ce_cache[class_idx];
    if (EXPECTED(ce != NULL)) {
        return ce == DEC_CE_MISSING ? NULL : ce;
    }
    ce = zend_lookup_class_ex(class_name, NULL, 0);
    d->ce_cache[class_idx] = ce ? ce : DEC_CE_MISSING;
    return ce;
}

/* Returns 1 if `class_name` is allowed by the current decode_ctx filter.
 * The set is pre-lowercased on caller side. */
static inline int dec_class_allowed(decode_ctx *d, zend_string *class_name) {
    if (EXPECTED(d->allowed_mode == ALLOWED_ALL)) return 1;
    if (d->allowed_mode == ALLOWED_NONE) return 0;
    /* ALLOWED_SET: case-insensitive lookup. PHP class names are stored
     * lowercased in the engine class table, and user-supplied names in
     * allowed_classes get pre-lowercased into d->allowed_set. */
    zend_string *lc = zend_string_tolower(class_name);
    int ok = zend_hash_exists(d->allowed_set, lc);
    zend_string_release(lc);
    return ok;
}

/* Install one decoded value as a property on `obj`. Mirrors TAG_OBJECT's
 * IS_INDIRECT-vs-dynamic dispatch with typed-slot checking. Used by both
 * TAG_OBJECT and TAG_OBJECT_MAGIC's __unserialize-missing fallback.
 *
 * Takes ownership of *tmp: on success it's moved into the slot; on
 * type-mismatch it's dtor'd. Returns 0/-1. */
static int dec_install_prop(zend_object *obj, HashTable *obj_props,
                            zend_string *key, zval *tmp) {
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
        zend_hash_add_new(obj_props, key, tmp);
    }
    return 0;
}

/* Apply a data array (from __serialize) to an object's properties when
 * __unserialize is unavailable. Matches PHP's native fallback: each
 * string key becomes a property write (typed or dynamic), int keys
 * become string-cast dynamic properties. Both regular classes without
 * __unserialize AND __PHP_Incomplete_Class for disallowed classes use
 * this path. */
static void dec_apply_data_as_props(zend_object *obj, HashTable *data_ht) {
    HashTable *obj_props = zend_std_get_properties(obj);
    zend_string *key;
    zend_ulong h;
    zval *val;
    ZEND_HASH_FOREACH_KEY_VAL(data_ht, h, key, val) {
        zval tmp;
        ZVAL_COPY(&tmp, val);  /* addref for the new owner */
        if (key) {
            (void)dec_install_prop(obj, obj_props, key, &tmp);
        } else {
            /* Int key — convert to string for the dynamic-property table,
             * matching PHP's behavior (creates property "0", "1", etc.). */
            zend_string *str_key = zend_long_to_str((zend_long)h);
            (void)dec_install_prop(obj, obj_props, str_key, &tmp);
            zend_string_release(str_key);
        }
    } ZEND_HASH_FOREACH_END();
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

/* Takes a uint64 because varint_read_u64 produces uint64. Bound-check
 * BEFORE narrowing — otherwise an attacker can craft idx = 2^32 (or any
 * multiple of dict_len) and the uint32 truncation lands on a valid slot. */
static inline zend_string *dec_get_zstr(decode_ctx *d, uint64_t idx) {
    if (UNEXPECTED(idx >= d->dict_len)) return NULL;
    return d->dict[idx];
}

static int decode_value(decode_ctx *d, zval *out);

/* Materialize the dict header. We eagerly allocate every dict slot and
 * pre-compute its hash. This trades a tiny up-front cost for one less branch
 * in the per-string hot path, plus zend_hash_add_new gets a hot hash on the
 * zend_string and skips its compute step. */
static int decode_header(decode_ctx *d) {
    if (d->len < 1) return -1;
    if (d->buf[d->pos++] != PHPSER_VERSION) return -1;
    uint64_t n;
    if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
    /* Each dict entry is at least 2 bytes (varint(0) + 0 content, but a
     * realistic minimum is 1 byte length + at least 1 byte content). Bound
     * N by remaining input so an oversized varint can't OOM the ecalloc. */
    if (n > UINT32_MAX || n > d->len - d->pos) return -1;
    d->dict_len = (uint32_t)n;
    d->dict = ecalloc(d->dict_len + 1, sizeof(zend_string *));
    for (uint32_t i = 0; i < d->dict_len; i++) {
        uint64_t slen;
        if (varint_read_u64(d->buf, d->len, &d->pos, &slen) < 0) return -1;
        if (slen > UINT32_MAX || d->pos + slen > d->len) return -1;
        d->dict[i] = zend_string_init((const char *)(d->buf + d->pos), (size_t)slen, 0);
        zend_string_hash_val(d->dict[i]);  /* warms the cached hash on the zend_string */
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
            zval_ptr_dtor(&d->deferred[i].data);
        }
        efree(d->deferred);
    }
    if (d->wakeup) {
        for (uint32_t i = 0; i < d->wakeup_len; i++) {
            OBJ_RELEASE(d->wakeup[i]);
        }
        efree(d->wakeup);
    }
    if (d->ce_cache) efree(d->ce_cache);
}

/* Read an Assoc/Object key (one byte tag + payload). Stores result in out_key,
 * caller decides where to insert. Returns 0/-1. */
/* Three flavors of assoc key: int, dict-indexed string, owned-string-inline.
 * The "owned" case allocates a fresh zend_string; the caller releases its
 * reference after handing it to zend_hash_add_new (which addrefs internally
 * for non-interned strings). */
enum { KV_LONG, KV_DICT_STR, KV_OWNED_STR };

typedef struct {
    int kind;
    int64_t lval;            /* KV_LONG */
    uint64_t dict_idx;       /* KV_DICT_STR — bound-check at decode time, not narrowed */
    zend_string *owned_str;  /* KV_OWNED_STR — refcount=1, caller releases */
} key_val;

static int decode_key(decode_ctx *d, key_val *out_key) {
    if (d->pos >= d->len) return -1;
    uint8_t tag = d->buf[d->pos++];
    if (tag == KEY_LONG) {
        out_key->kind = KV_LONG;
        return varint_read_i64(d->buf, d->len, &d->pos, &out_key->lval);
    } else if (tag == KEY_STR) {
        uint64_t idx;
        if (varint_read_u64(d->buf, d->len, &d->pos, &idx) < 0) return -1;
        out_key->kind = KV_DICT_STR;
        out_key->dict_idx = idx;
        return 0;
    } else if (tag == KEY_STR_INLINE) {
        uint64_t slen;
        if (varint_read_u64(d->buf, d->len, &d->pos, &slen) < 0) return -1;
        if (slen > UINT32_MAX || d->pos + slen > d->len) return -1;
        out_key->kind = KV_OWNED_STR;
        out_key->owned_str = zend_string_init(
            (const char *)d->buf + d->pos, (size_t)slen, 0);
        d->pos += slen;
        return 0;
    }
    return -1;
}

static int decode_value_inner(decode_ctx *d, zval *out);

/* Wrapper enforces two invariants the inner cases would otherwise have to
 * pepper through every error path:
 *   1. C-stack guard — adversarial wire format can recurse arbitrarily;
 *      reject when d->depth hits MAX_DEPTH.
 *   2. On -1 return, *out is always IS_UNDEF. Callers may safely
 *      `zval_ptr_dtor(out)` (no-op) or ignore *out entirely. Without this,
 *      inner cases that partially populate *out before failing (TAG_OBJECT*
 *      after object_init_ex, TAG_NEW_REF after ZVAL_REF) leak the partial
 *      value through caller code paths that assume "tmp" is uninitialized.
 *
 * `out` may be uninitialized on entry, so we don't read Z_TYPE_P(out) until
 * after the inner has had a chance to set it. Inner is responsible for
 * writing SOMETHING (either a real value, or IS_UNDEF, or a partial value
 * we'll dtor) before returning. The dtor-then-UNDEF on -1 cleans up
 * partials cascade-style. */
static int decode_value(decode_ctx *d, zval *out) {
    if (UNEXPECTED(d->depth >= MAX_DEPTH)) {
        ZVAL_UNDEF(out);
        return -1;
    }
    d->depth++;
    int rc = decode_value_inner(d, out);
    d->depth--;
    /* Inner pre-sets *out to IS_UNDEF as its first action; subsequent
     * writes may partially populate it (IS_OBJECT after object_init_ex,
     * IS_REFERENCE after ZVAL_REF) before a downstream -1. Cascade-clean
     * here so every caller can assume "*out is IS_UNDEF on -1". */
    if (UNEXPECTED(rc < 0) && Z_TYPE_P(out) != IS_UNDEF) {
        zval_ptr_dtor(out);
        ZVAL_UNDEF(out);
    }
    return rc;
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
            /* Reject IS_REFERENCE wrapping IS_REFERENCE: phpser's encoder
             * can't produce this (PHP's own ref machinery flattens on bind),
             * but adversarial wire (TAG_NEW_REF directly inside TAG_NEW_REF,
             * or TAG_NEW_REF + TAG_REF to itself) can. Either shape forces
             * PHP's teardown to recurse through ref->val->ref->val...,
             * blowing the C stack on a payload as small as 256 bytes. The
             * legitimate cycle pattern (`$a = []; $a[] = &$a;`) routes
             * through a container so ref->val ends up IS_ARRAY/IS_OBJECT,
             * which terminates cleanly via the gc cycle collector.
             *
             * Clear ref->val before returning so the refcount cycle breaks:
             * the self-ref case (TAG_NEW_REF + TAG_REF to itself) has
             * ref->val holding a refcount back on ref itself, and the
             * id_table teardown can't drive refcount to zero unless that
             * self-edge is cut here. The chained case (nested TAG_NEW_REF)
             * also drops one cycle edge so id_table teardown can free
             * downstream entries cleanly via the standard refcount chain. */
            if (Z_TYPE(ref->val) == IS_REFERENCE) {
                zval_ptr_dtor(&ref->val);
                ZVAL_UNDEF(&ref->val);
                return -1;
            }
            return 0;
        }
        case TAG_NULL:  ZVAL_NULL(out); return 0;
        case TAG_FALSE: ZVAL_FALSE(out); return 0;
        case TAG_TRUE:  ZVAL_TRUE(out); return 0;
        case TAG_LONG: {
            int64_t v;
            if (varint_read_i64(d->buf, d->len, &d->pos, &v) < 0) return -1;
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
            ZVAL_STR_COPY(out, zs);  /* addrefs the cached string */
            return 0;
        }
        case TAG_STR_INLINE: {
            uint64_t slen;
            if (varint_read_u64(d->buf, d->len, &d->pos, &slen) < 0) return -1;
            if (slen > UINT32_MAX || d->pos + slen > d->len) return -1;
            /* Fresh allocation, refcount=1 — ZVAL_STR consumes it without
             * bumping refcount, so no extra release is needed. */
            zend_string *zs = zend_string_init(
                (const char *)d->buf + d->pos, (size_t)slen, 0);
            d->pos += slen;
            ZVAL_STR(out, zs);
            return 0;
        }
        case TAG_PACKED_LONGS: {
            uint64_t n;
            if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
            /* Bound N by remaining buffer — each element is at least 1 byte
             * (the smallest varint). Without this a malformed payload could
             * announce N=2^32 elements and OOM us before parsing fails. */
            if (n > UINT32_MAX || n > d->len - d->pos) return -1;
            /* Pre-sized HT + direct arPacked writes. This is the hot path
             * we expect to beat igbinary on numeric arrays. */
            zend_array *arr = zend_new_array((uint32_t)n);
            zend_hash_real_init_packed(arr);
            for (uint64_t i = 0; i < n; i++) {
                int64_t lv;
                if (varint_read_i64(d->buf, d->len, &d->pos, &lv) < 0) {
                    zend_array_destroy(arr);
                    return -1;
                }
                ZVAL_LONG(&arr->arPacked[i], lv);
            }
            arr->nNumUsed = (uint32_t)n;
            arr->nNumOfElements = (uint32_t)n;
            arr->nNextFreeElement = (zend_long)n;
            ZVAL_ARR(out, arr);
            return 0;
        }
        case TAG_PACKED_DOUBLES: {
            uint64_t n;
            if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
            /* PACKED_DOUBLES requires exactly 8*N bytes — check fits exactly. */
            if (n > UINT32_MAX || n > (d->len - d->pos) / 8) return -1;
            zend_array *arr = zend_new_array((uint32_t)n);
            zend_hash_real_init_packed(arr);
            for (uint64_t i = 0; i < n; i++) {
                ZVAL_DOUBLE(&arr->arPacked[i], le64_read(d->buf + d->pos));
                d->pos += 8;
            }
            arr->nNumUsed = (uint32_t)n;
            arr->nNumOfElements = (uint32_t)n;
            arr->nNextFreeElement = (zend_long)n;
            ZVAL_ARR(out, arr);
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
            arr->nNumUsed = (uint32_t)n;
            arr->nNumOfElements = (uint32_t)n;
            arr->nNextFreeElement = (zend_long)n;
            ZVAL_ARR(out, arr);
            return 0;
        }
        case TAG_PACKED_MIXED: {
            uint64_t n;
            if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
            if (n > UINT32_MAX || n > d->len - d->pos) return -1;
            zend_array *arr = zend_new_array((uint32_t)n);
            zend_hash_real_init_packed(arr);
            for (uint64_t i = 0; i < n; i++) {
                if (decode_value(d, &arr->arPacked[i]) < 0) {
                    arr->nNumUsed = (uint32_t)i;
                    zend_array_destroy(arr);
                    return -1;
                }
            }
            arr->nNumUsed = (uint32_t)n;
            arr->nNumOfElements = (uint32_t)n;
            arr->nNextFreeElement = (zend_long)n;
            ZVAL_ARR(out, arr);
            return 0;
        }
        case TAG_OBJECT_LEGACY: {
            uint64_t class_idx, blen;
            if (varint_read_u64(d->buf, d->len, &d->pos, &class_idx) < 0) return -1;
            if (varint_read_u64(d->buf, d->len, &d->pos, &blen) < 0) return -1;
            if (blen > UINT32_MAX || d->pos + blen > d->len) return -1;

            zend_string *class_name = dec_get_zstr(d, class_idx);
            if (!class_name) return -1;

            /* allowed_classes filter: if this class is disallowed, build an
             * incomplete-class instance and skip the legacy serializer
             * payload. ce->unserialize would otherwise instantiate the real
             * class, which the option exists to prevent. */
            if (!dec_class_allowed(d, class_name)) {
                d->pos += blen;
                if (dec_make_incomplete(out, class_name) < 0) return -1;
                dec_register(d, out);
                return 0;
            }
            zend_class_entry *ce = dec_class_resolve(d, class_idx, class_name);
            if (!ce || ce->unserialize == NULL) {
                /* Unknown class or no C-level unserializer — skip past the
                 * payload bytes, yield NULL, and register a NULL id-slot so
                 * subsequent TAG_REFs to this position resolve to NULL
                 * (matching what we'd see on a partial decode). */
                d->pos += blen;
                ZVAL_NULL(out);
                dec_register(d, out);
                return 0;
            }
            /* ce->unserialize is responsible for initializing *out; we don't
             * pre-init it. var_hash is NULL — same caveat as the encode side. */
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
            zend_string *class_name = dec_get_zstr(d, class_idx);
            if (!class_name) return -1;

            /* allowed_classes filter: build incomplete-class and decode
             * the data array; the array is then applied as dynamic
             * properties via dec_apply_data_as_props (round-3 fix —
             * matches PHP's behavior of preserving serialized state on
             * __PHP_Incomplete_Class). We must consume the data tree
             * from the stream either way to keep id counts aligned. */
            int allowed = dec_class_allowed(d, class_name);
            zend_class_entry *ce = allowed
                ? dec_class_resolve(d, class_idx, class_name) : NULL;
            if (!ce) ce = allowed ? zend_standard_class_def : PHP_IC_ENTRY;

            if (object_init_ex(out, ce) != SUCCESS) {
                ZVAL_NULL(out);
                return -1;
            }
            if (!allowed) php_store_class_name(out, class_name);
            /* Register the empty object NOW, before decoding the data array.
             * A back-ref inside the data array can then resolve to this very
             * object (cycles through __serialize-class payloads). __unserialize
             * is deferred to the end of the decode pass so the whole graph is
             * stitched before any user code runs that might depend on it. */
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
                /* No __unserialize (either class lacks it, or class is
                 * disallowed/incomplete). Apply the data array as
                 * properties — matches PHP's native fallback: a class
                 * with __serialize() but no __unserialize() round-trips
                 * via the regular O: format, and a disallowed class
                 * preserves the serialized props on __PHP_Incomplete_Class.
                 * Without this, the serialized state is silently
                 * dropped. */
                dec_apply_data_as_props(Z_OBJ_P(out), Z_ARRVAL(data));
                zval_ptr_dtor(&data);
            }
            return 0;
        }
        case TAG_ENUM: {
            uint64_t class_idx, case_idx;
            if (varint_read_u64(d->buf, d->len, &d->pos, &class_idx) < 0) return -1;
            if (varint_read_u64(d->buf, d->len, &d->pos, &case_idx) < 0) return -1;
            zend_string *cname = dec_get_zstr(d, class_idx);
            zend_string *casename = dec_get_zstr(d, case_idx);
            if (!cname || !casename) return -1;
            /* allowed_classes also gates enum cases. PHP's serialize emits
             * enums as "E:..." and the filter applies the same as for O:. */
            if (!dec_class_allowed(d, cname)) {
                if (dec_make_incomplete(out, cname) < 0) return -1;
                dec_register(d, out);
                return 0;
            }
            zend_class_entry *ce = dec_class_resolve(d, class_idx, cname);
            if (!ce || !(ce->ce_flags & ZEND_ACC_ENUM)) return -1;
            zend_object *obj = zend_enum_get_case(ce, casename);
            if (!obj) return -1;
            ZVAL_OBJ_COPY(out, obj);
            dec_register(d, out);
            return 0;
        }
        case TAG_OBJECT: {
            uint64_t class_idx, nprops;
            if (varint_read_u64(d->buf, d->len, &d->pos, &class_idx) < 0) return -1;
            if (varint_read_u64(d->buf, d->len, &d->pos, &nprops) < 0) return -1;
            /* Each prop is at least 2 bytes (key idx varint + value tag). */
            if (nprops > UINT32_MAX || nprops > (d->len - d->pos) / 2) return -1;
            zend_string *class_name = dec_get_zstr(d, class_idx);
            if (!class_name) return -1;

            /* allowed_classes filter: disallowed classes decode into
             * __PHP_Incomplete_Class with the original name attached as
             * the magic property. Properties below still land via the
             * normal IS_INDIRECT / dynamic-prop write path. */
            int allowed = dec_class_allowed(d, class_name);
            zend_class_entry *ce = allowed
                ? dec_class_resolve(d, class_idx, class_name) : PHP_IC_ENTRY;
            /* Resolve the class. If autoloading fails or the class doesn't
             * exist, fall back to stdClass — refusing would break round-trips
             * of payloads written before a class was registered. */
            if (!ce) ce = zend_standard_class_def;

            if (object_init_ex(out, ce) != SUCCESS) {
                ZVAL_NULL(out);
                return -1;
            }
            if (!allowed) php_store_class_name(out, class_name);
            zend_object *obj = Z_OBJ_P(out);
            /* Register before decoding properties so a back-ref inside a
             * property value (cycles, shared subobjects) can resolve to this
             * in-progress object. */
            dec_register(d, out);

            /* Mirror PHP's var_unserializer property installation. The
             * standard library bypasses write_property (which rejects
             * NUL-prefixed mangled names) and zend_get_property_info
             * (which would enforce visibility scope on private props).
             * Instead it looks up the mangled key directly in the object's
             * properties HT — declared props show up as IS_INDIRECT entries
             * pointing into properties_table, and we write straight into
             * that slot. Dynamic props fall through to zend_hash_update. */
            HashTable *obj_props = zend_std_get_properties(obj);

            for (uint64_t i = 0; i < nprops; i++) {
                uint64_t key_idx;
                if (varint_read_u64(d->buf, d->len, &d->pos, &key_idx) < 0) goto obj_fail;
                zend_string *key = dec_get_zstr(d, key_idx);
                if (!key) goto obj_fail;
                zval tmp;
                if (decode_value(d, &tmp) < 0) goto obj_fail;
                /* dec_install_prop mirrors var_unserializer.re:608-708:
                 * IS_INDIRECT slots get typed-property verification, dynamic
                 * props go through zend_hash_update with overwrite semantics.
                 * On typed-mismatch tmp is dtor'd inside the helper. */
                if (dec_install_prop(obj, obj_props, key, &tmp) < 0) goto obj_fail;
                continue;
            obj_fail:
                zval_ptr_dtor(out); ZVAL_NULL(out); return -1;
            }
            /* Queue __wakeup if the class defines it. Deferred to end-of-pass
             * so the full graph is stitched before any wakeup hook runs and
             * sees cycle back-edges intact. Lookup via function_table —
             * __wakeup isn't cached on zend_class_entry. */
            if (zend_hash_find_known_hash(&ce->function_table,
                    ZSTR_KNOWN(ZEND_STR_WAKEUP)) != NULL) {
                dec_defer_wakeup(d, obj);
            }
            return 0;
        }
        case TAG_ASSOC: {
            uint64_t n;
            if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
            /* Each assoc entry is at least 2 bytes (key tag + value tag). */
            if (n > UINT32_MAX || n > (d->len - d->pos) / 2) return -1;
            zend_array *arr = zend_new_array((uint32_t)n);
            for (uint64_t i = 0; i < n; i++) {
                /* Zero-init so gcc's -Wmaybe-uninitialized doesn't false-
                 * positive on k.lval/k.owned_str: decode_key sets k.kind
                 * AND the corresponding union member before returning 0,
                 * but compiler flow analysis can't see the invariant. */
                key_val k = {0};
                if (decode_key(d, &k) < 0) goto assoc_fail;
                zval tmp;
                if (decode_value(d, &tmp) < 0) {
                    if (k.kind == KV_OWNED_STR) zend_string_release(k.owned_str);
                    goto assoc_fail;
                }
                /* Use update semantics so a crafted payload with duplicate
                 * keys collapses to the last value (matches PHP's native
                 * unserialize behavior) rather than producing a corrupt
                 * HT where count() and $arr[key] disagree. The encoder
                 * doesn't emit duplicates from trusted sources — but the
                 * decoder is the security boundary. */
                switch (k.kind) {
                    case KV_LONG:
                        zend_hash_index_update(arr, (zend_ulong)k.lval, &tmp);
                        break;
                    case KV_DICT_STR: {
                        zend_string *zs = dec_get_zstr(d, k.dict_idx);
                        if (!zs) { zval_ptr_dtor(&tmp); goto assoc_fail; }
                        zend_hash_update(arr, zs, &tmp);
                        break;
                    }
                    case KV_OWNED_STR:
                        zend_hash_update(arr, k.owned_str, &tmp);
                        /* hash addref'd it for the bucket; drop our ref. */
                        zend_string_release(k.owned_str);
                        break;
                }
                continue;
            assoc_fail:
                zend_array_destroy(arr);
                return -1;
            }
            ZVAL_ARR(out, arr);
            return 0;
        }
        default:
            return -1;
    }
}

/* -------------------------------------------------------------------------
 * HMAC-SHA256 over framed payloads. Used by phpser_serialize_signed /
 * phpser_unserialize_signed to detect tampered cache entries when the
 * storage layer is untrusted (e.g. shared Memcached).
 *
 * Wire format for signed payloads: [raw frame bytes][32-byte HMAC tag].
 * The tag is computed over the raw frame only. Separate function names
 * (vs. a flag in unserialize) mean the caller's intent is explicit at the
 * call site — no magic-byte detection, no chance of accidentally accepting
 * an unsigned payload through the signed path.
 * ------------------------------------------------------------------------- */

/* Cached at MINIT. ext/hash is mandatory since PHP 7.4 and lookup never
 * fails for a builtin algo; we still null-check defensively. */
static const php_hash_ops *phpser_sha256_ops = NULL;

/* HMAC-SHA256 of `data` under `key`. Writes a 32-byte tag to `out`.
 * Returns 0 on success, -1 if SHA256 ops aren't available. */
static int phpser_hmac_sha256(
    const unsigned char *key, size_t key_len,
    const unsigned char *data, size_t data_len,
    unsigned char out[PHPSER_HMAC_TAG_LEN])
{
    const php_hash_ops *ops = phpser_sha256_ops;
    if (UNEXPECTED(!ops)) return -1;
    size_t bs = ops->block_size;
    /* SHA256 block size is 64 — small enough for a stack buffer. */
    unsigned char K[64];
    if (UNEXPECTED(bs > sizeof(K))) return -1;

    void *ctx = emalloc(ops->context_size);
    if (key_len > bs) {
        ops->hash_init(ctx, NULL);
        ops->hash_update(ctx, key, key_len);
        ops->hash_final(K, ctx);
        memset(K + ops->digest_size, 0, bs - ops->digest_size);
    } else {
        memcpy(K, key, key_len);
        if (key_len < bs) memset(K + key_len, 0, bs - key_len);
    }
    /* Inner: H((K^ipad) || data) */
    for (size_t i = 0; i < bs; i++) K[i] ^= 0x36;
    ops->hash_init(ctx, NULL);
    ops->hash_update(ctx, K, bs);
    ops->hash_update(ctx, data, data_len);
    ops->hash_final(out, ctx);
    /* Outer: H((K^opad) || inner) */
    for (size_t i = 0; i < bs; i++) K[i] ^= (0x36 ^ 0x5c);
    ops->hash_init(ctx, NULL);
    ops->hash_update(ctx, K, bs);
    ops->hash_update(ctx, out, ops->digest_size);
    ops->hash_final(out, ctx);
    /* Wipe key material before returning. After the outer XOR pass K
     * still holds K^opad — XOR with 0x5c…5c recovers K. A separate
     * stack-read primitive elsewhere in the process would otherwise
     * leak the signing key. Also wipe the hash context (SHA256 internal
     * state derived from the key) before freeing. ZEND_SECURE_ZERO ==
     * explicit_bzero on glibc / RtlSecureZeroMemory on Windows — the
     * compiler cannot optimize it away. */
    ZEND_SECURE_ZERO(K, sizeof(K));
    ZEND_SECURE_ZERO(ctx, ops->context_size);
    efree(ctx);
    return 0;
}

/* Constant-time byte compare. Returns 1 if all `n` bytes are equal.
 * Mirrors the pattern PHP's hash_equals() uses internally — avoids the
 * early-exit timing leak that memcmp would have. */
static int phpser_ct_eq(const unsigned char *a, const unsigned char *b, size_t n) {
    unsigned char r = 0;
    for (size_t i = 0; i < n; i++) r |= (unsigned char)(a[i] ^ b[i]);
    return r == 0;
}

/* -------------------------------------------------------------------------
 * Public functions.
 * ------------------------------------------------------------------------- */

/* Reusable encode: produce a framed payload zend_string from a zval.
 * Caller owns the returned zend_string. Returns NULL only when the input
 * nests deeper than MAX_DEPTH — a lossy, undecodable payload would
 * otherwise result. When throw_on_overflow is set, that case also throws
 * an Exception so the userland entry points fail loud; the session handler
 * passes false and degrades to a warning, because a thrown exception during
 * request shutdown (the session auto-save path runs with no execution
 * frame) surfaces as an uncaught fatal that the hook cannot intercept.
 * Every other input yields a payload (worst case an empty TAG_NULL body). */
static zend_string *phpser_encode_zval(zval *value, bool throw_on_overflow) {
    encode_ctx ctx;
    enc_ctx_init(&ctx);

    smart_str body = {0};
    /* Pre-size body to skip 5-6 geometric grow cycles that an unconfigured
     * smart_str does on its way up to a typical multi-KB cache payload.
     * Estimate from top-level array element count at ~16 bytes/elem; cap
     * at 256 KB so a pathological top-level array doesn't request a
     * gigabyte upfront. Beyond the cap, smart_str's geometric grow
     * handles the rest. Scalars and objects get the 256-byte default. */
    size_t body_estimate = 256;
    if (Z_TYPE_P(value) == IS_ARRAY) {
        uint32_t n = zend_hash_num_elements(Z_ARRVAL_P(value));
        if (n > 16) {
            size_t est = (size_t)n * 16;
            body_estimate = est > (256 * 1024) ? (256 * 1024) : est;
        }
    }
    smart_str_alloc(&body, body_estimate, 0);
    encode_value(&body, &ctx, value);

    /* Reject over-deep input rather than ship a truncated payload. The
     * decoder caps at the same MAX_DEPTH, so a payload that hit the encode
     * cap would decode to NULL in full — silent total data loss. Fail
     * loud here instead. */
    if (UNEXPECTED(ctx.depth_exceeded)) {
        smart_str_free(&body);
        enc_ctx_destroy(&ctx);
        if (throw_on_overflow) {
            zend_throw_exception_ex(zend_ce_exception, 0,
                "phpser: maximum nesting depth (%d) exceeded", MAX_DEPTH);
        }
        return NULL;
    }
    size_t body_len = body.s ? ZSTR_LEN(body.s) : 0;

    /* Frame: [version][varint ndict][per-entry varint(len)+bytes][body].
     *
     * Pre-size `out` to skip smart_str's geometric grow-and-copy cascade.
     * Worst-case header: 1 (version) + 5 (max varint for dict_len)
     *                  + ndict * (5 max varint + name bytes).
     * Add body_len for the final concat. Single allocation, no realloc. */
    size_t header_max = 1 + 5;
    for (uint32_t i = 0; i < ctx.dict_len; i++) {
        header_max += 5 + ZSTR_LEN(ctx.dict[i]);
    }

    smart_str out = {0};
    /* +1 for the smart_str_0 NUL terminator at the end. */
    smart_str_alloc(&out, header_max + body_len + 1, 0);

    smart_str_appendc(&out, PHPSER_VERSION);
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

    enc_ctx_destroy(&ctx);
    return out.s;
}

/* Reusable decode: parse a framed payload into `out`. Returns 0 on success,
 * -1 on any framing/buffer error. On error, `out` is set to NULL.
 *
 * allowed_mode + allowed_set control which classes can decode normally; the
 * rest land in __PHP_Incomplete_Class. NULL/ALLOWED_ALL means no filter. */
static int phpser_decode_buf_opts(
    const char *str, size_t str_len, zval *out,
    int allowed_mode, HashTable *allowed_set)
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
    /* Run deferred __unserialize calls in encounter order. The graph is now
     * fully stitched, so cycles and shared subobjects are visible to user
     * code. If a call throws, propagate by stopping; remaining data arrays
     * (and partially populated objects) still get cleaned up by
     * decode_destroy. */
    for (uint32_t i = 0; i < d.deferred_len; i++) {
        zend_object *obj = d.deferred[i].obj;
        zval *data = &d.deferred[i].data;
        zval retval;
        ZVAL_UNDEF(&retval);
        zend_call_known_instance_method_with_1_params(
            obj->ce->__unserialize, obj, &retval, data);
        zval_ptr_dtor(&retval);
        if (UNEXPECTED(EG(exception))) goto done;
    }
    /* __wakeup runs strictly after every __unserialize has resolved, so a
     * wakeup hook on object A sees a fully-stitched object B even when B's
     * __unserialize would have rebuilt B's state. PHP's var_unserializer
     * uses the same two-phase ordering. */
    for (uint32_t i = 0; i < d.wakeup_len; i++) {
        zend_object *obj = d.wakeup[i];
        zval *wake_fn_zv = zend_hash_find_known_hash(
            &obj->ce->function_table, ZSTR_KNOWN(ZEND_STR_WAKEUP));
        if (!wake_fn_zv) continue;
        zval retval;
        ZVAL_UNDEF(&retval);
        zend_call_known_instance_method_with_0_params(
            Z_FUNC_P(wake_fn_zv), obj, &retval);
        zval_ptr_dtor(&retval);
        if (UNEXPECTED(EG(exception))) goto done;
    }
done:
    decode_destroy(&d);
    /* Adversarial wire can hand back a top-level IS_REFERENCE (TAG_NEW_REF
     * as the root value). PHP's calling convention rejects that for
     * functions not declared by-ref: ZEND_DO_ICALL asserts IS_REFERENCE
     * iff the function returns by-ref. phpser_unserialize is plain-return,
     * so unwrap before handing back. Matches PHP native unserialize, which
     * collapses a top-level `R:n;` to the underlying value. */
    if (Z_TYPE_P(out) == IS_REFERENCE) {
        zend_reference *ref = Z_REF_P(out);
        zval inner;
        ZVAL_COPY(&inner, &ref->val);
        zval_ptr_dtor(out);
        ZVAL_COPY_VALUE(out, &inner);
    }
    /* If a deferred __unserialize or __wakeup threw, signal failure to C
     * callers. PHP-level callers are unwound by Zend's exception handling
     * at the function boundary regardless, but the session handler
     * (PS_SERIALIZER_DECODE_FUNC) reads our return value to decide whether
     * to persist `$_SESSION`. A swallowed exception would let it commit
     * a partially-stitched graph. */
    return UNEXPECTED(EG(exception)) ? -1 : 0;
}

#ifdef HAVE_PHP_SESSION
/* Back-compat wrapper for the session handler, which doesn't take options. */
static int phpser_decode_buf(const char *str, size_t str_len, zval *out) {
    return phpser_decode_buf_opts(str, str_len, out, ALLOWED_ALL, NULL);
}
#endif

PHP_FUNCTION(phpser_serialize) {
    zval *value;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(value)
    ZEND_PARSE_PARAMETERS_END();
    zend_string *out = phpser_encode_zval(value, /* throw_on_overflow */ true);
    if (UNEXPECTED(!out)) {
        RETURN_THROWS();  /* depth-cap exception already pending */
    }
    RETVAL_STR(out);
}

/* -------------------------------------------------------------------------
 * Session serializer integration. Gated on HAVE_PHP_SESSION (set by config.m4
 * when phpize detects the session extension). Compiled out under the local
 * dev Makefile, which doesn't define the macro.
 * ------------------------------------------------------------------------- */

#ifdef HAVE_PHP_SESSION
PS_SERIALIZER_ENCODE_FUNC(phpser) {
    /* PS(http_session_vars) is the $_SESSION zval reference. Deref before
     * encoding so the wire format stores a plain array, not IS_REFERENCE. */
    zval *session_vars = &PS(http_session_vars);
    if (Z_TYPE_P(session_vars) == IS_REFERENCE) {
        session_vars = Z_REFVAL_P(session_vars);
    }
    /* throw_on_overflow=false: the session auto-save runs at request
     * shutdown with no execution frame, where a thrown exception surfaces as
     * an uncaught fatal the hook can't intercept. So encode returns NULL
     * without throwing on over-depth; we degrade to the E_WARNING that
     * session.c itself uses for write failures. php_session_save_current_state
     * writes ZSTR_EMPTY_ALLOC() on a NULL result (the empty write is
     * unavoidable through the serializer-hook contract, and a >MAX_DEPTH
     * $_SESSION can't round-trip regardless). */
    zend_string *out = phpser_encode_zval(session_vars, /* throw_on_overflow */ false);
    if (UNEXPECTED(!out)) {
        php_error_docref(NULL, E_WARNING,
            "phpser: $_SESSION not serialized — nesting depth exceeds %d", MAX_DEPTH);
        return NULL;
    }
    return out;
}

PS_SERIALIZER_DECODE_FUNC(phpser) {
    zval decoded;
    if (vallen == 0) {
        /* A brand-new session reads back as an empty string from storage.
         * PHP's native serializers treat that as an empty session; feeding
         * it to the decoder fails the version-byte check (FAILURE), which
         * makes the engine emit "Failed to decode session object" and
         * destroy the session on every first request. Start empty instead. */
        array_init(&decoded);
    } else if (phpser_decode_buf(val, vallen, &decoded) < 0) {
        return FAILURE;
    } else if (Z_TYPE(decoded) != IS_ARRAY) {
        /* Sessions expect an array on the way back; if the payload decoded
         * to something else, swap in an empty array so PS(http_session_vars)
         * stays sane for $_SESSION. */
        zval_ptr_dtor(&decoded);
        array_init(&decoded);
    }
    if (!Z_ISUNDEF(PS(http_session_vars))) {
        zval_ptr_dtor(&PS(http_session_vars));
    }
    ZVAL_NEW_REF(&PS(http_session_vars), &decoded);
    Z_ADDREF_P(&PS(http_session_vars));
    /* Re-bind the userland $_SESSION symbol to the new reference. Without
     * this, user code reads the previous request's array via the stale
     * symbol-table entry — the PS(http_session_vars) slot is updated but
     * $_SESSION still aliases the old one. PHP's own session decoders
     * (php_serialize, php) do this. See session.c:986 (PS_SERIALIZER_
     * DECODE_FUNC(php_serialize)). */
    zend_string *var_name = ZSTR_INIT_LITERAL("_SESSION", 0);
    zend_hash_update_ind(&EG(symbol_table), var_name, &PS(http_session_vars));
    zend_string_release_ex(var_name, 0);
    return SUCCESS;
}
#endif /* HAVE_PHP_SESSION */

/* Parse a phpser_unserialize options array. On success, *out_set may be
 * non-NULL and the caller must free it. Returns -1 on type error (an
 * exception is already thrown). param_idx is the arg position for the
 * error message (2 for unserialize, 3 for unserialize_signed); fname is
 * the calling function's name for the TypeError text. */
static int parse_unserialize_options(
    HashTable *options_ht, int param_idx, const char *fname,
    int *out_mode, HashTable **out_set)
{
    *out_mode = ALLOWED_ALL;
    *out_set = NULL;
    if (!options_ht) return 0;
    zval *ac = zend_hash_str_find(options_ht, "allowed_classes",
                                   sizeof("allowed_classes") - 1);
    if (!ac) return 0;
    if (Z_TYPE_P(ac) == IS_FALSE) { *out_mode = ALLOWED_NONE; return 0; }
    if (Z_TYPE_P(ac) == IS_TRUE)  { *out_mode = ALLOWED_ALL;  return 0; }
    if (Z_TYPE_P(ac) == IS_ARRAY) {
        /* Build a lowercased-name lookup set. PHP class names are
         * case-insensitive; storing pre-lowered keeps the per-object
         * filter check to one zend_hash_exists. */
        *out_mode = ALLOWED_SET;
        *out_set = emalloc(sizeof(HashTable));
        zend_hash_init(*out_set, zend_hash_num_elements(Z_ARRVAL_P(ac)),
                       NULL, NULL, 0);
        zval *cn;
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ac), cn) {
            if (Z_TYPE_P(cn) != IS_STRING) {
                /* Match PHP's native unserialize: non-string entry in
                 * the allowed_classes array is a TypeError. Silently
                 * skipping would let a misconfigured allowlist pass
                 * unflagged. */
                zend_hash_destroy(*out_set);
                efree(*out_set);
                *out_set = NULL;
                zend_type_error(
                    "%s(): allowed_classes option must "
                    "be an array of class names, %s given",
                    fname, zend_zval_value_name(cn));
                return -1;
            }
            zend_string *lc = zend_string_tolower(Z_STR_P(cn));
            zval one; ZVAL_TRUE(&one);
            zend_hash_add(*out_set, lc, &one);
            zend_string_release(lc);
        } ZEND_HASH_FOREACH_END();
        return 0;
    }
    zend_argument_value_error(param_idx,
        "allowed_classes option must be array or bool");
    return -1;
}

PHP_FUNCTION(phpser_unserialize) {
    char *str;
    size_t str_len;
    HashTable *options_ht = NULL;
    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STRING(str, str_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_HT(options_ht)
    ZEND_PARSE_PARAMETERS_END();

    int allowed_mode;
    HashTable *allowed_set;
    if (parse_unserialize_options(options_ht, 2, "phpser_unserialize",
                                  &allowed_mode, &allowed_set) < 0) {
        RETURN_THROWS();
    }

    phpser_decode_buf_opts(str, str_len, return_value, allowed_mode, allowed_set);

    if (allowed_set) {
        zend_hash_destroy(allowed_set);
        efree(allowed_set);
    }
}

PHP_FUNCTION(phpser_serialize_signed) {
    zval *value;
    char *key;
    size_t key_len;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ZVAL(value)
        Z_PARAM_STRING(key, key_len)
    ZEND_PARSE_PARAMETERS_END();

    zend_string *frame = phpser_encode_zval(value, /* throw_on_overflow */ true);
    if (UNEXPECTED(!frame)) {
        RETURN_THROWS();  /* depth-cap exception already pending */
    }
    /* Reallocate to add tag space. zend_string_extend grows the underlying
     * allocation and bumps ZSTR_LEN. The 32 trailing bytes become the HMAC. */
    size_t frame_len = ZSTR_LEN(frame);
    zend_string *signed_str = zend_string_extend(frame, frame_len + PHPSER_HMAC_TAG_LEN, 0);
    unsigned char *tag = (unsigned char *)ZSTR_VAL(signed_str) + frame_len;
    if (phpser_hmac_sha256(
            (const unsigned char *)key, key_len,
            (const unsigned char *)ZSTR_VAL(signed_str), frame_len,
            tag) < 0) {
        zend_string_release(signed_str);
        zend_throw_exception(zend_ce_exception,
            "phpser: SHA256 hash ops unavailable (ext/hash not loaded?)", 0);
        RETURN_THROWS();
    }
    ZSTR_VAL(signed_str)[frame_len + PHPSER_HMAC_TAG_LEN] = '\0';
    RETURN_STR(signed_str);
}

PHP_FUNCTION(phpser_unserialize_signed) {
    char *payload;
    size_t payload_len;
    char *key;
    size_t key_len;
    HashTable *options_ht = NULL;
    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STRING(payload, payload_len)
        Z_PARAM_STRING(key, key_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_HT(options_ht)
    ZEND_PARSE_PARAMETERS_END();

    /* Payload must include at least the 32-byte tag. Anything shorter is
     * either truncated or never signed — reject without leaking which. */
    if (payload_len < PHPSER_HMAC_TAG_LEN) {
        zend_throw_exception(zend_ce_exception,
            "phpser: signed payload too short", 0);
        RETURN_THROWS();
    }
    size_t frame_len = payload_len - PHPSER_HMAC_TAG_LEN;
    unsigned char expected[PHPSER_HMAC_TAG_LEN];
    if (phpser_hmac_sha256(
            (const unsigned char *)key, key_len,
            (const unsigned char *)payload, frame_len,
            expected) < 0) {
        zend_throw_exception(zend_ce_exception,
            "phpser: SHA256 hash ops unavailable (ext/hash not loaded?)", 0);
        RETURN_THROWS();
    }
    if (!phpser_ct_eq(expected,
                      (const unsigned char *)payload + frame_len,
                      PHPSER_HMAC_TAG_LEN)) {
        zend_throw_exception(zend_ce_exception,
            "phpser: signature verification failed", 0);
        RETURN_THROWS();
    }

    int allowed_mode;
    HashTable *allowed_set;
    if (parse_unserialize_options(options_ht, 3, "phpser_unserialize_signed",
                                  &allowed_mode, &allowed_set) < 0) {
        RETURN_THROWS();
    }

    phpser_decode_buf_opts(payload, frame_len, return_value, allowed_mode, allowed_set);

    if (allowed_set) {
        zend_hash_destroy(allowed_set);
        efree(allowed_set);
    }
}

/* -------------------------------------------------------------------------
 * Module plumbing.
 * ------------------------------------------------------------------------- */

#include "phpser_arginfo.h"

static PHP_MINIT_FUNCTION(phpser) {
#if (defined(COMPILE_DL_PHPSER) || defined(ZEND_COMPILE_DL_EXT)) && defined(ZTS)
    /* Populate our TLS slot from the host PHP's thread-local state pointer.
     * Required for dynamically-loaded extensions under ZTS — otherwise
     * macros that touch CG/EG via the TSRMLS cache crash on lookup.
     *
     * The OR covers both build paths: phpize-generated config.m4 defines
     * COMPILE_DL_<EXTNAME>; the hand-rolled Makefile defines the generic
     * ZEND_COMPILE_DL_EXT. Without this widening, a ZTS build via the
     * Makefile path would compile but crash on first CG/EG access. */
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
#ifdef HAVE_PHP_SESSION
    /* Register session.serialize_handler = phpser. Best-effort: the session
     * extension may not be loaded (rare in shared-build setups), and we
     * tolerate that case silently. */
    php_session_register_serializer(
        PHP_PHPSER_EXTNAME,
        PS_SERIALIZER_ENCODE_NAME(phpser),
        PS_SERIALIZER_DECODE_NAME(phpser));
#endif
    /* Cache SHA256 ops for HMAC signing. ext/hash is mandatory since PHP
     * 7.4 so this never fails in normal builds; we still null-check at
     * call time. php_hash_fetch_ops only reads the algo name for the table
     * lookup — it doesn't retain the pointer — so the lookup zend_string is
     * a transient stack-local released immediately, not a module global. */
    zend_string *algo = zend_string_init("sha256", sizeof("sha256") - 1, 0);
    phpser_sha256_ops = php_hash_fetch_ops(algo);
    zend_string_release(algo);
    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(phpser) {
    phpser_sha256_ops = NULL;
    return SUCCESS;
}

static PHP_MINFO_FUNCTION(phpser) {
    php_info_print_table_start();
    php_info_print_table_row(2, "phpser support", "enabled");
    php_info_print_table_row(2, "version", PHP_PHPSER_VERSION);
#ifdef HAVE_PHP_SESSION
    php_info_print_table_row(2, "session.serialize_handler", "available");
#else
    php_info_print_table_row(2, "session.serialize_handler", "disabled (compiled without session)");
#endif
    php_info_print_table_end();
}

/* Declare session as an OPTIONAL dependency. The runtime declaration
 * (vs. only config.m4's PHP_ADD_EXTENSION_DEP) is what controls MINIT
 * ordering — without it, alphabetical conf.d load order can put our
 * MINIT before session's, and php_session_register_serializer runs
 * against a session module that isn't ready yet.
 * See ~/ai/wiki/architecture/php-extension-c-conventions.md "Cross-extension
 * class lookup at MINIT" for the failure mode. */
static const zend_module_dep phpser_deps[] = {
    /* hash is mandatory since PHP 7.4 — we use its SHA256 ops for the
     * signed-payload HMAC. ZEND_MOD_REQUIRED forces the engine to load
     * hash's MINIT before ours so phpser_sha256_ops resolves cleanly. */
    ZEND_MOD_REQUIRED("hash")
#ifdef HAVE_PHP_SESSION
    ZEND_MOD_OPTIONAL("session")
#endif
    ZEND_MOD_END
};

zend_module_entry phpser_module_entry = {
    STANDARD_MODULE_HEADER_EX,
    NULL,
    phpser_deps,
    PHP_PHPSER_EXTNAME,
    ext_functions,
    PHP_MINIT(phpser),
    PHP_MSHUTDOWN(phpser),
    NULL, NULL,
    PHP_MINFO(phpser),
    PHP_PHPSER_VERSION,
    STANDARD_MODULE_PROPERTIES,
};

/* Under ZTS, a dynamically-loaded extension needs its own TLS slot cache
 * because the host PHP's per-thread state pointer isn't accessible
 * through static linkage. Config defines ZEND_ENABLE_STATIC_TSRMLS_CACHE;
 * the matching cache_define + cache_update at MINIT completes the wiring.
 * On NTS builds both macros expand to nothing. */
ZEND_TSRMLS_CACHE_DEFINE()

/* get_module() is the dynamic-loader entry point; emit it only for a shared
 * build. A hypothetical static link into the PHP binary would otherwise get
 * a duplicate/clashing symbol. The OR mirrors the MINIT TSRMLS guard so the
 * hand-rolled dev Makefile (which defines ZEND_COMPILE_DL_EXT rather than
 * COMPILE_DL_PHPSER) still produces a loadable .so for `make test`. */
#if defined(COMPILE_DL_PHPSER) || defined(ZEND_COMPILE_DL_EXT)
ZEND_GET_MODULE(phpser)
#endif
