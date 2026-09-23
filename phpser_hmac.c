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
 * ------------------------------------------------------------------------- */

#include "phpser_int.h"

#include <string.h>

const php_hash_ops *phpser_sha256_ops = NULL;

int phpser_hmac_sha256(
    const unsigned char *key, size_t key_len,
    const unsigned char *data, size_t data_len,
    unsigned char out[PHPSER_HMAC_TAG_LEN])
{
    const php_hash_ops *ops = phpser_sha256_ops;
    if (UNEXPECTED(!ops)) return -1;
    size_t bs = ops->block_size;
    unsigned char K[64];
    if (UNEXPECTED(bs > sizeof(K))) return -2;

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
    /* K still contains reversible K^opad. Securely wipe it and the key-derived
     * hash state so the compiler cannot eliminate the erasure. */
    ZEND_SECURE_ZERO(K, sizeof(K));
    ZEND_SECURE_ZERO(ctx, ops->context_size);
    efree(ctx);
    return 0;
}

/* Do not use memcmp: its early exit leaks the matching prefix. */
int phpser_ct_eq(const unsigned char *a, const unsigned char *b, size_t n) {
    unsigned char r = 0;
    for (size_t i = 0; i < n; i++) r |= (unsigned char)(a[i] ^ b[i]);
    return r == 0;
}
