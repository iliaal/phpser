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

#ifdef HAVE_PHP_SESSION
# include "ext/session/php_session.h"
#endif

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

/* Assoc key tags (one byte before the key's payload). */
#define KEY_LONG  0x00
#define KEY_STR   0x01

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
    uint64_t v = 0;
    int shift = 0;
    while (*pos < buflen) {
        uint8_t b = buf[(*pos)++];
        if (shift >= 64) return -1;
        v |= ((uint64_t)(b & 0x7f)) << shift;
        if ((b & 0x80) == 0) {
            *out = v;
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

/* -------------------------------------------------------------------------
 * Encode state. Two-tier intern:
 *   - inline_cache: 16-slot ring of (zend_string*, dict_idx). Hit rate on
 *     rowset shapes is near 100% after the first row because PHP shares
 *     interned literal pointers across all rows. ~5-10 cmps per lookup;
 *     no hash function call, no HashTable bucket walk.
 *   - hash_map: HashTable keyed by zend_string content. ONLY consulted on
 *     inline-cache miss AND only meaningful when two distinct zend_string
 *     allocations share content (e.g., runtime-built strings that happen
 *     to equal a literal). For typical cache payloads it's mostly dead
 *     weight, but we keep it for correctness.
 *   - dict: index→zend_string* array we emit at the head.
 * ------------------------------------------------------------------------- */

#define INTERN_CACHE_SIZE 16

/* Threshold under which we skip the hash_map check on miss and just add the
 * string to the dict. Rationale: for small dicts the chance of two distinct
 * zend_string allocations colliding by content is tiny, and we'd pay an
 * unconditional hash+probe per unique string. The risk: a duplicate slips
 * through if two same-content allocations show up in the first N strings.
 * Behavior remains correct — wire format just gets a bit larger. */
#define HASH_MAP_THRESHOLD 32

typedef struct {
    zend_string *ptr;
    uint32_t idx;
} intern_slot;

/* Cycle guard for recursive encode. Cache payloads usually nest 5-10 deep;
 * anything beyond MAX_DEPTH is treated as a runaway and aborted. The most
 * common way to hit this is IS_REFERENCE pointing back into an ancestor —
 * we flatten references rather than encode them as shareable, so a true
 * self-ref turns into an infinite chase without this counter. */
#define MAX_DEPTH 4096

typedef struct {
    intern_slot inline_cache[INTERN_CACHE_SIZE];
    uint32_t cache_filled;        /* number of valid slots, up to INTERN_CACHE_SIZE */
    uint32_t cache_next;          /* next replacement slot when full (LRU-ish ring) */
    HashTable hash_map;           /* (zend_string content -> u32 dict_idx), only used past threshold */
    zend_string **dict;           /* index -> zend_string* (borrowed) */
    uint32_t dict_len;
    uint32_t dict_cap;
    uint32_t depth;
} encode_ctx;

static void enc_ctx_init(encode_ctx *e) {
    memset(e->inline_cache, 0, sizeof(e->inline_cache));
    e->cache_filled = 0;
    e->cache_next = 0;
    zend_hash_init(&e->hash_map, 16, NULL, NULL, 0);
    e->dict = NULL;
    e->dict_len = 0;
    e->dict_cap = 0;
    e->depth = 0;
}

static void enc_ctx_destroy(encode_ctx *e) {
    zend_hash_destroy(&e->hash_map);
    if (e->dict) efree(e->dict);
}

static inline void enc_cache_insert(encode_ctx *e, zend_string *zs, uint32_t idx) {
    uint32_t slot;
    if (e->cache_filled < INTERN_CACHE_SIZE) {
        slot = e->cache_filled++;
    } else {
        slot = e->cache_next;
        e->cache_next = (e->cache_next + 1) % INTERN_CACHE_SIZE;
    }
    e->inline_cache[slot].ptr = zs;
    e->inline_cache[slot].idx = idx;
}

static uint32_t enc_intern_zstr(encode_ctx *e, zend_string *zs) {
    /* Inline-cache pointer scan. Hot loop — straight comparisons, no hash. */
    for (uint32_t i = 0; i < e->cache_filled; i++) {
        if (e->inline_cache[i].ptr == zs) return e->inline_cache[i].idx;
    }

    /* Content lookup, only once the dict is large enough that the chance
     * of two distinct allocations colliding by content matters. For small
     * payloads this branch is skipped — saves the per-unique-string hash. */
    if (e->dict_len >= HASH_MAP_THRESHOLD) {
        zval *hit = zend_hash_find(&e->hash_map, zs);
        if (hit) {
            uint32_t idx = (uint32_t)Z_LVAL_P(hit);
            enc_cache_insert(e, zs, idx);
            return idx;
        }
    }

    /* Append to dict. */
    if (e->dict_len == e->dict_cap) {
        e->dict_cap = e->dict_cap ? e->dict_cap * 2 : 16;
        e->dict = erealloc(e->dict, e->dict_cap * sizeof(zend_string *));
    }
    uint32_t idx = e->dict_len++;
    e->dict[idx] = zs;

    /* Once we cross the threshold, backfill the hash_map so subsequent
     * misses can hit it. Pay this O(N) cost exactly once. */
    if (e->dict_len == HASH_MAP_THRESHOLD) {
        for (uint32_t i = 0; i < e->dict_len; i++) {
            zval iz; ZVAL_LONG(&iz, i);
            zend_hash_add(&e->hash_map, e->dict[i], &iz);
        }
    } else if (e->dict_len > HASH_MAP_THRESHOLD) {
        zval iz; ZVAL_LONG(&iz, idx);
        zend_hash_add(&e->hash_map, zs, &iz);
    }
    enc_cache_insert(e, zs, idx);
    return idx;
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
     * chases the same zval indefinitely. */
    if (UNEXPECTED(e->depth >= MAX_DEPTH)) {
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
            double d = Z_DVAL_P(v);
            smart_str_appendl(body, (char *)&d, 8);
            return;
        }
        case IS_STRING: {
            uint32_t idx = enc_intern_zstr(e, Z_STR_P(v));
            emit_tag_and_varint(body, TAG_STR_DICT, idx);
            return;
        }
        case IS_ARRAY:
            encode_hashtable(body, e, Z_ARRVAL_P(v));
            return;
        case IS_REFERENCE:
            /* Flatten — proper ref handling is V2. */
            encode_value(body, e, Z_REFVAL_P(v));
            return;
        case IS_OBJECT: {
            zend_object *obj = Z_OBJ_P(v);
            uint32_t class_idx = enc_intern_zstr(e, obj->ce->name);
            HashTable *props = obj->handlers->get_properties(obj);
            /* Count live entries — get_properties can include $this-style
             * entries we want to skip and tombstones (IS_UNDEF). */
            uint32_t nprops = 0;
            if (props) {
                Bucket *b = props->arData;
                Bucket *end = b + props->nNumUsed;
                for (; b < end; b++) {
                    if (Z_TYPE(b->val) == IS_UNDEF || !b->key) continue;
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
                    if (Z_TYPE(b->val) == IS_UNDEF || !b->key) continue;
                    varint_write_u64(body, enc_intern_zstr(e, b->key));
                    encode_value(body, e, &b->val);
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
 * per-element tag emission and lets the decoder use a single tight loop. */
static uint8_t detect_packed_run(HashTable *ht, uint32_t n_used) {
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
        case IS_STRING: return TAG_PACKED_STRINGS;
        default:        return TAG_PACKED_MIXED; /* unreachable */
    }
}

static void encode_hashtable(smart_str *body, encode_ctx *e, HashTable *ht) {
    uint32_t n_used = ht->nNumUsed;
    uint32_t n_elems = ht->nNumOfElements;
    int is_packed = HT_IS_PACKED(ht);

    if (is_packed && n_used == n_elems && n_used > 0) {
        /* Dense packed — try to use a typed-run tag. */
        uint8_t tag = detect_packed_run(ht, n_used);
        smart_str_appendc(body, tag);
        varint_write_u64(body, n_elems);
        zval *zp = ht->arPacked;
        if (tag == TAG_PACKED_LONGS) {
            for (uint32_t i = 0; i < n_used; i++) {
                varint_write_i64(body, Z_LVAL(zp[i]));
            }
        } else if (tag == TAG_PACKED_DOUBLES) {
            for (uint32_t i = 0; i < n_used; i++) {
                double d = Z_DVAL(zp[i]);
                smart_str_appendl(body, (char *)&d, 8);
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
            emit_tag_and_varint(body, KEY_STR, enc_intern_zstr(e, b->key));
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

typedef struct {
    const uint8_t *buf;
    size_t len;
    size_t pos;
    zend_string **dict;        /* eagerly allocated zend_strings per slot */
    uint32_t dict_len;
    int error;
} decode_ctx;

static inline zend_string *dec_get_zstr(decode_ctx *d, uint32_t idx) {
    if (UNEXPECTED(idx >= d->dict_len)) {
        d->error = 1;
        return NULL;
    }
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
    if (n > UINT32_MAX) return -1;
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
}

/* Read an Assoc/Object key (one byte tag + payload). Stores result in out_key,
 * caller decides where to insert. Returns 0/-1. */
typedef struct {
    int is_string;
    int64_t lval;        /* when !is_string */
    uint32_t dict_idx;   /* when is_string */
} key_val;

static int decode_key(decode_ctx *d, key_val *out_key) {
    if (d->pos >= d->len) return -1;
    uint8_t tag = d->buf[d->pos++];
    if (tag == KEY_LONG) {
        out_key->is_string = 0;
        return varint_read_i64(d->buf, d->len, &d->pos, &out_key->lval);
    } else if (tag == KEY_STR) {
        uint64_t idx;
        if (varint_read_u64(d->buf, d->len, &d->pos, &idx) < 0) return -1;
        out_key->is_string = 1;
        out_key->dict_idx = (uint32_t)idx;
        return 0;
    }
    return -1;
}

static int decode_value(decode_ctx *d, zval *out) {
    if (d->pos >= d->len) return -1;
    uint8_t tag = d->buf[d->pos++];
    switch (tag) {
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
            double v;
            memcpy(&v, d->buf + d->pos, 8);
            d->pos += 8;
            ZVAL_DOUBLE(out, v);
            return 0;
        }
        case TAG_STR_DICT: {
            uint64_t idx;
            if (varint_read_u64(d->buf, d->len, &d->pos, &idx) < 0) return -1;
            zend_string *zs = dec_get_zstr(d, (uint32_t)idx);
            if (!zs) return -1;
            ZVAL_STR_COPY(out, zs);  /* addrefs the cached string */
            return 0;
        }
        case TAG_PACKED_LONGS: {
            uint64_t n;
            if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
            if (n > UINT32_MAX) return -1;
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
            if (n > UINT32_MAX || d->pos + n * 8 > d->len) return -1;
            zend_array *arr = zend_new_array((uint32_t)n);
            zend_hash_real_init_packed(arr);
            for (uint64_t i = 0; i < n; i++) {
                double dv;
                memcpy(&dv, d->buf + d->pos, 8);
                d->pos += 8;
                ZVAL_DOUBLE(&arr->arPacked[i], dv);
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
            if (n > UINT32_MAX) return -1;
            zend_array *arr = zend_new_array((uint32_t)n);
            zend_hash_real_init_packed(arr);
            for (uint64_t i = 0; i < n; i++) {
                uint64_t idx;
                if (varint_read_u64(d->buf, d->len, &d->pos, &idx) < 0) {
                    arr->nNumUsed = (uint32_t)i;
                    zend_array_destroy(arr);
                    return -1;
                }
                zend_string *zs = dec_get_zstr(d, (uint32_t)idx);
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
            if (n > UINT32_MAX) return -1;
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
        case TAG_OBJECT: {
            uint64_t class_idx, nprops;
            if (varint_read_u64(d->buf, d->len, &d->pos, &class_idx) < 0) return -1;
            if (varint_read_u64(d->buf, d->len, &d->pos, &nprops) < 0) return -1;
            zend_string *class_name = dec_get_zstr(d, (uint32_t)class_idx);
            if (!class_name) return -1;

            /* Resolve the class. If autoloading fails or the class doesn't
             * exist, fall back to stdClass — refusing would break round-trips
             * of payloads written before a class was registered. */
            zend_class_entry *ce = zend_lookup_class_ex(class_name, NULL, 0);
            if (!ce) ce = zend_standard_class_def;

            /* Materialize the object. Trusted (mostly): we built this payload.
             * Skipping ce->create_object hooks and __wakeup for V1 — those
             * land with full Serializable / __unserialize support later. */
            if (object_init_ex(out, ce) != SUCCESS) {
                ZVAL_NULL(out);
                return -1;
            }
            zend_object *obj = Z_OBJ_P(out);

            for (uint64_t i = 0; i < nprops; i++) {
                uint64_t key_idx;
                if (varint_read_u64(d->buf, d->len, &d->pos, &key_idx) < 0) goto obj_fail;
                zend_string *key = dec_get_zstr(d, (uint32_t)key_idx);
                if (!key) goto obj_fail;
                zval tmp;
                if (decode_value(d, &tmp) < 0) goto obj_fail;
                /* update_property handles typed-property coercion and the
                 * declared-vs-dynamic property bag distinction. Slower than
                 * direct HT write but correct across class shapes. */
                obj->handlers->write_property(obj, key, &tmp, NULL);
                zval_ptr_dtor(&tmp);
                continue;
            obj_fail:
                zval_ptr_dtor(out);
                ZVAL_NULL(out);
                return -1;
            }
            return 0;
        }
        case TAG_ASSOC: {
            uint64_t n;
            if (varint_read_u64(d->buf, d->len, &d->pos, &n) < 0) return -1;
            if (n > UINT32_MAX) return -1;
            zend_array *arr = zend_new_array((uint32_t)n);
            for (uint64_t i = 0; i < n; i++) {
                key_val k;
                if (decode_key(d, &k) < 0) goto assoc_fail;
                zval tmp;
                if (decode_value(d, &tmp) < 0) goto assoc_fail;
                if (k.is_string) {
                    zend_string *zs = dec_get_zstr(d, k.dict_idx);
                    if (!zs) { zval_ptr_dtor(&tmp); goto assoc_fail; }
                    /* _add_new variants skip the existence check — we know the
                     * encoder doesn't emit duplicate keys per bucket. */
                    zend_hash_add_new(arr, zs, &tmp);
                } else {
                    zend_hash_index_add_new(arr, (zend_ulong)k.lval, &tmp);
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
 * Public functions.
 * ------------------------------------------------------------------------- */

/* Reusable encode: produce a framed payload zend_string from a zval.
 * Caller owns the returned zend_string. Never returns NULL — worst case
 * is an empty TAG_NULL body. */
static zend_string *phpser_encode_zval(zval *value) {
    encode_ctx ctx;
    enc_ctx_init(&ctx);

    smart_str body = {0};
    encode_value(&body, &ctx, value);

    /* Frame: [version][varint ndict][per-entry varint(len)+bytes][body] */
    smart_str out = {0};
    smart_str_appendc(&out, PHPSER_VERSION);
    varint_write_u64(&out, ctx.dict_len);
    for (uint32_t i = 0; i < ctx.dict_len; i++) {
        zend_string *zs = ctx.dict[i];
        varint_write_u64(&out, ZSTR_LEN(zs));
        smart_str_appendl(&out, ZSTR_VAL(zs), ZSTR_LEN(zs));
    }
    if (body.s) {
        smart_str_appendl(&out, ZSTR_VAL(body.s), ZSTR_LEN(body.s));
        smart_str_free(&body);
    }
    smart_str_0(&out);

    enc_ctx_destroy(&ctx);
    return out.s;
}

/* Reusable decode: parse a framed payload into `out`. Returns 0 on success,
 * -1 on any framing/buffer error. On error, `out` is set to NULL. */
static int phpser_decode_buf(const char *str, size_t str_len, zval *out) {
    decode_ctx d = {0};
    d.buf = (const uint8_t *)str;
    d.len = str_len;

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
    decode_destroy(&d);
    return 0;
}

PHP_FUNCTION(phpser_serialize) {
    zval *value;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(value)
    ZEND_PARSE_PARAMETERS_END();
    RETVAL_STR(phpser_encode_zval(value));
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
    return phpser_encode_zval(session_vars);
}

PS_SERIALIZER_DECODE_FUNC(phpser) {
    zval decoded;
    if (phpser_decode_buf(val, vallen, &decoded) < 0) {
        return FAILURE;
    }
    /* Sessions expect an array on the way back; if the payload decoded to
     * something else, swap in an empty array so PS(http_session_vars) stays
     * sane for $_SESSION. */
    if (Z_TYPE(decoded) != IS_ARRAY) {
        zval_ptr_dtor(&decoded);
        array_init(&decoded);
    }
    if (!Z_ISUNDEF(PS(http_session_vars))) {
        zval_ptr_dtor(&PS(http_session_vars));
    }
    ZVAL_NEW_REF(&PS(http_session_vars), &decoded);
    Z_ADDREF_P(&PS(http_session_vars));
    return SUCCESS;
}
#endif /* HAVE_PHP_SESSION */

PHP_FUNCTION(phpser_unserialize) {
    char *str;
    size_t str_len;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(str, str_len)
    ZEND_PARSE_PARAMETERS_END();
    phpser_decode_buf(str, str_len, return_value);
}

/* -------------------------------------------------------------------------
 * Module plumbing.
 * ------------------------------------------------------------------------- */

ZEND_BEGIN_ARG_INFO_EX(arginfo_phpser_serialize, 0, 0, 1)
    ZEND_ARG_INFO(0, value)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_phpser_unserialize, 0, 0, 1)
    ZEND_ARG_INFO(0, str)
ZEND_END_ARG_INFO()

static const zend_function_entry phpser_functions[] = {
    PHP_FE(phpser_serialize,   arginfo_phpser_serialize)
    PHP_FE(phpser_unserialize, arginfo_phpser_unserialize)
    PHP_FE_END
};

static PHP_MINIT_FUNCTION(phpser) {
#ifdef HAVE_PHP_SESSION
    /* Register session.serialize_handler = phpser. Best-effort: the session
     * extension may not be loaded (rare in shared-build setups), and we
     * tolerate that case silently. */
    php_session_register_serializer(
        PHP_PHPSER_EXTNAME,
        PS_SERIALIZER_ENCODE_NAME(phpser),
        PS_SERIALIZER_DECODE_NAME(phpser));
#endif
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

zend_module_entry phpser_module_entry = {
    STANDARD_MODULE_HEADER,
    PHP_PHPSER_EXTNAME,
    phpser_functions,
    PHP_MINIT(phpser),
    NULL, NULL, NULL,
    PHP_MINFO(phpser),
    PHP_PHPSER_VERSION,
    STANDARD_MODULE_PROPERTIES,
};

ZEND_GET_MODULE(phpser)
