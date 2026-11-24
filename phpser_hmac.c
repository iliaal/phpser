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

/* -------------------------------------------------------------------------
 * HMAC-SHA256 over framed payloads. Used by phpser_serialize_signed /
 * phpser_unserialize_signed to detect tampered cache entries when the
 * storage layer is untrusted (e.g. shared Memcached).
 *
 * Wire format for signed payloads: [raw frame bytes][32-byte HMAC tag].
 * The tag is computed over the raw frame only. Separate entry points (not a
 * flag or magic-byte detection) keep an unsigned payload from ever being
 * accepted through the signed path.
 *
 * Each thread keeps the SHA-256 states after compressing K^ipad and K^opad
 * for its most recent key, so a repeat key costs two fewer compressions and
 * no allocation. The entry holds the key itself (raw bytes when at most 64,
 * else its SHA-256 digest) plus key-equivalent midstates, in static
 * thread-local memory until a different key replaces it in place or
 * phpser_hmac_mshutdown() wipes it at module or thread shutdown.
 * ------------------------------------------------------------------------- */

#include "phpser_int.h"
#include "phpser_sha256.h"

#include <string.h>

#define SHA256_BLOCK 64

const php_hash_ops *phpser_sha256_ops = NULL;

typedef struct {
    /* NULL until this thread's first HMAC selects an implementation. */
    phpser_sha256_blocks_fn blocks;
    bool key_valid;
    /* The key as HMAC sees it: raw bytes zero-padded to the block size, or
     * SHA-256(key) zero-padded when the key is longer than a block. Keys that
     * normalize to the same block produce identical tags, so this is the
     * exact cache identity. */
    unsigned char key_block[SHA256_BLOCK];
    uint32_t inner[8];
    uint32_t outer[8];
} phpser_hmac_state;

ZEND_TLS phpser_hmac_state hmac_state;

static zend_always_inline void store_be32(unsigned char *p, uint32_t v)
{
    p[0] = (unsigned char)(v >> 24);
    p[1] = (unsigned char)(v >> 16);
    p[2] = (unsigned char)(v >> 8);
    p[3] = (unsigned char)v;
}

static zend_always_inline void store_digest(unsigned char out[32],
                                            const uint32_t st[8])
{
    for (int i = 0; i < 8; i++) store_be32(out + 4 * i, st[i]);
}

/* Absorbs `data` and the SHA-256 padding into `st`. `prefix_len` counts bytes
 * already compressed into `st` (one block for a keyed midstate). */
static void sha256_absorb_final(phpser_sha256_blocks_fn blocks, uint32_t st[8],
                                const unsigned char *data, size_t len,
                                uint64_t prefix_len)
{
    size_t full = len / SHA256_BLOCK;
    size_t rem = len % SHA256_BLOCK;
    size_t tail = rem < SHA256_BLOCK - 8 ? SHA256_BLOCK : 2 * SHA256_BLOCK;
    unsigned char buf[2 * SHA256_BLOCK];
    uint64_t bits = (prefix_len + (uint64_t)len) << 3;

    if (full) blocks(st, data, full);
    if (rem) memcpy(buf, data + full * SHA256_BLOCK, rem);
    buf[rem] = 0x80;
    memset(buf + rem + 1, 0, tail - 8 - rem - 1);
    store_be32(buf + tail - 8, (uint32_t)(bits >> 32));
    store_be32(buf + tail - 4, (uint32_t)bits);
    blocks(st, buf, tail / SHA256_BLOCK);
    /* Only an over-long key (prefix_len == 0) puts secret bytes in `buf`. */
    if (prefix_len == 0) {
        ZEND_SECURE_ZERO(buf, tail);
    }
}

static zend_always_inline phpser_sha256_blocks_fn hmac_blocks_fn(void)
{
    phpser_hmac_state *hs = &hmac_state;
    if (UNEXPECTED(!hs->blocks)) {
        const char *backend;
        hs->blocks = phpser_sha256_select(&backend);
    }
    return hs->blocks;
}

static void hmac_compute_midstates(phpser_sha256_blocks_fn blocks,
                                   const unsigned char k[SHA256_BLOCK],
                                   uint32_t inner[8], uint32_t outer[8])
{
    unsigned char pad[SHA256_BLOCK];
    for (size_t i = 0; i < SHA256_BLOCK; i++) pad[i] = k[i] ^ 0x36;
    memcpy(inner, phpser_sha256_iv, 8 * sizeof(uint32_t));
    blocks(inner, pad, 1);
    for (size_t i = 0; i < SHA256_BLOCK; i++) pad[i] ^= 0x36 ^ 0x5c;
    memcpy(outer, phpser_sha256_iv, 8 * sizeof(uint32_t));
    blocks(outer, pad, 1);
    ZEND_SECURE_ZERO(pad, sizeof(pad));
}

static void hmac_load_key(phpser_hmac_state *hs, phpser_sha256_blocks_fn blocks,
                          const unsigned char k[SHA256_BLOCK])
{
    hmac_compute_midstates(blocks, k, hs->inner, hs->outer);
    memcpy(hs->key_block, k, SHA256_BLOCK);
    hs->key_valid = true;
}

static void hmac_finish_midstates(phpser_sha256_blocks_fn blocks,
                                  const uint32_t inner[8], const uint32_t outer[8],
                                  const unsigned char *data, size_t data_len,
                                  unsigned char out[PHPSER_HMAC_TAG_LEN])
{
    uint32_t st[8];
    /* Inner: H((K^ipad) || data), resumed from the cached midstate. */
    memcpy(st, inner, sizeof(st));
    sha256_absorb_final(blocks, st, data, data_len, SHA256_BLOCK);

    /* Outer: H((K^opad) || inner). 64 + 32 bytes always pads to one block. */
    unsigned char blk[SHA256_BLOCK];
    store_digest(blk, st);
    blk[32] = 0x80;
    memset(blk + 33, 0, SHA256_BLOCK - 33 - 8);
    store_be32(blk + 56, 0);
    store_be32(blk + 60, (SHA256_BLOCK + PHPSER_HMAC_TAG_LEN) * 8);
    memcpy(st, outer, sizeof(st));
    blocks(st, blk, 1);
    store_digest(out, st);

    ZEND_SECURE_ZERO(st, sizeof(st));
    ZEND_SECURE_ZERO(blk, sizeof(blk));
}

int phpser_hmac_prepare_midstate(
    const unsigned char *key, size_t key_len,
    uint32_t inner[8], uint32_t outer[8])
{
    if (UNEXPECTED(!phpser_sha256_ops)) return -1;
    phpser_sha256_blocks_fn blocks = hmac_blocks_fn();
    unsigned char k[SHA256_BLOCK];
    if (key_len > SHA256_BLOCK) {
        uint32_t st[8];
        memcpy(st, phpser_sha256_iv, sizeof(st));
        sha256_absorb_final(blocks, st, key, key_len, 0);
        store_digest(k, st);
        memset(k + 32, 0, SHA256_BLOCK - 32);
        ZEND_SECURE_ZERO(st, sizeof(st));
    } else {
        memcpy(k, key, key_len);
        memset(k + key_len, 0, SHA256_BLOCK - key_len);
    }
    hmac_compute_midstates(blocks, k, inner, outer);
    ZEND_SECURE_ZERO(k, sizeof(k));
    return 0;
}

int phpser_hmac_sha256_midstate(
    const uint32_t inner[8], const uint32_t outer[8],
    const unsigned char *data, size_t data_len,
    unsigned char out[PHPSER_HMAC_TAG_LEN])
{
    if (UNEXPECTED(!phpser_sha256_ops)) return -1;
    phpser_sha256_blocks_fn blocks = hmac_blocks_fn();
    hmac_finish_midstates(blocks, inner, outer, data, data_len, out);
    return 0;
}

int phpser_hmac_sha256(
    const unsigned char *key, size_t key_len,
    const unsigned char *data, size_t data_len,
    unsigned char out[PHPSER_HMAC_TAG_LEN])
{
    if (UNEXPECTED(!phpser_sha256_ops)) return -1;

    phpser_sha256_blocks_fn blocks = hmac_blocks_fn();
    phpser_hmac_state *hs = &hmac_state;

    unsigned char k[SHA256_BLOCK];
    uint32_t st[8];
    if (key_len > SHA256_BLOCK) {
        memcpy(st, phpser_sha256_iv, sizeof(st));
        sha256_absorb_final(blocks, st, key, key_len, 0);
        store_digest(k, st);
        memset(k + 32, 0, SHA256_BLOCK - 32);
        ZEND_SECURE_ZERO(st, sizeof(st));
    } else {
        memcpy(k, key, key_len);
        memset(k + key_len, 0, SHA256_BLOCK - key_len);
    }
    /* Only hit/miss is observable: the compare itself has no early exit, so
     * timing cannot reveal how much of a new key matches the cached one. */
    if (!hs->key_valid || !phpser_ct_eq(k, hs->key_block, SHA256_BLOCK)) {
        hmac_load_key(hs, blocks, k);
    }
    ZEND_SECURE_ZERO(k, sizeof(k));

    hmac_finish_midstates(blocks, hs->inner, hs->outer, data, data_len, out);
    return 0;
}

void phpser_hmac_mshutdown(void)
{
    ZEND_SECURE_ZERO(&hmac_state, sizeof(hmac_state));
}

/* Do not use memcmp: its early exit leaks the matching prefix. */
int phpser_ct_eq(const unsigned char *a, const unsigned char *b, size_t n) {
    unsigned char r = 0;
    for (size_t i = 0; i < n; i++) r |= (unsigned char)(a[i] ^ b[i]);
    return r == 0;
}
