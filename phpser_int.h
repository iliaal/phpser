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

/* Internal cross-TU declarations; hot encode/decode helpers stay static in
 * phpser.c to preserve inlining. This header is not installed. */
#ifndef PHP_PHPSER_INT_H
#define PHP_PHPSER_INT_H

#include "php.h"
#include "php_phpser.h"
#include "ext/hash/php_hash.h"

/* SHA256 output size; the signed-payload tag length. */
#define PHPSER_HMAC_TAG_LEN 32

/* Depth/size failures persist an invalid frame so the next session read
 * reports failure instead of treating an empty string as a new session. */
#define PHPSER_SESSION_TOMBSTONE "phpser:session-not-serialized"

/* Bound C-stack use on deeply nested input, including ASAN builds. */
#define MAX_DEPTH 512

/* allowed_classes filter modes for phpser_decode_buf_opts. */
enum {
    ALLOWED_ALL = 0,
    ALLOWED_NONE,
    ALLOWED_SET
};

/* Reusable-encode outcome. Explains a NULL return so the session encoder
 * does not blame "depth" for a size or exception abort. */
typedef enum {
    PHPSER_ENC_OK = 0,
    PHPSER_ENC_DEPTH,
    PHPSER_ENC_SIZE,
    PHPSER_ENC_EXCEPTION,
} phpser_enc_status;

/* Cached at MINIT (defined in phpser_hmac.c). ext/hash is always present, but
 * callers still null-check. */
extern const php_hash_ops *phpser_sha256_ops;

/* HMAC-SHA256 of `data` under `key`. Writes a 32-byte tag to `out`.
 * Returns 0 on success, -1 if SHA256 ops aren't available, -2 if the
 * reported block size exceeds the stack pad. (phpser_hmac.c) */
int phpser_hmac_sha256(
    const unsigned char *key, size_t key_len,
    const unsigned char *data, size_t data_len,
    unsigned char out[PHPSER_HMAC_TAG_LEN]);

/* Constant-time byte compare. Returns 1 if all `n` bytes are equal.
 * (phpser_hmac.c) */
int phpser_ct_eq(const unsigned char *a, const unsigned char *b, size_t n);

/* Reusable encode: produce a framed payload zend_string from a zval.
 * Caller owns the returned zend_string. Returns NULL when a hook throws or
 * cleanup raises, or when depth/size limits would make the frame
 * undecodable. With throw_on_overflow, the userland entry points turn limit
 * failures into exceptions; the session handler passes false and degrades
 * them to warnings, because request-shutdown auto-save may have no
 * execution frame to catch one. (phpser.c) */
zend_string *phpser_encode_zval_ex(zval *value, bool throw_on_overflow,
                                   phpser_enc_status *status);
zend_string *phpser_encode_zval(zval *value, bool throw_on_overflow);

/* Reusable decode: parse a framed payload into `out`. Returns 0 on success,
 * -1 on any framing/buffer error. On error, `out` is set to NULL.
 *
 * allowed_mode + allowed_set control which classes can decode normally; the
 * rest land in __PHP_Incomplete_Class. NULL/ALLOWED_ALL means no filter.
 * require_exact rejects trailing bytes after a complete value; the unsigned
 * entry point leaves it off (historical leniency), signed + session pass it.
 * (phpser.c) */
int phpser_decode_buf_opts(
    const char *str, size_t str_len, zval *out,
    int allowed_mode, HashTable *allowed_set, bool require_exact);

#endif /* PHP_PHPSER_INT_H */
