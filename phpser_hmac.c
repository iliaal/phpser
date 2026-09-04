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
 * The tag is computed over the raw frame only. Separate function names
 * (vs. a flag in unserialize) mean the caller's intent is explicit at the
 * call site — no magic-byte detection, no chance of accidentally accepting
 * an unsigned payload through the signed path.
 * ------------------------------------------------------------------------- */

#include "phpser_int.h"

#include <string.h>

const php_hash_ops *phpser_sha256_ops = NULL;

/* HMAC-SHA256 of `data` under `key`. Writes a 32-byte tag to `out`.
 * Returns 0 on success, -1 if SHA256 ops aren't available, -2 if the
 * reported block size exceeds the stack pad (a version-skewed ext/hash
 * would otherwise fail with the misleading "unavailable" message). */
int phpser_hmac_sha256(
    const unsigned char *key, size_t key_len,
    const unsigned char *data, size_t data_len,
    unsigned char out[PHPSER_HMAC_TAG_LEN])
{
    const php_hash_ops *ops = phpser_sha256_ops;
    if (UNEXPECTED(!ops)) return -1;
    size_t bs = ops->block_size;
    /* SHA256 block size is 64 — small enough for a stack buffer. */
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
int phpser_ct_eq(const unsigned char *a, const unsigned char *b, size_t n) {
    unsigned char r = 0;
    for (size_t i = 0; i < n; i++) r |= (unsigned char)(a[i] ^ b[i]);
    return r == 0;
}
