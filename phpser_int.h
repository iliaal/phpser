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
 * phpser_int.h — internal shared declarations for the phpser translation
 * units (phpser.c, phpser_hmac.c, phpser_session.c, phpser_module.c).
 *
 * This header carries ONLY shared constants and cross-TU entry points.
 * All hot encode/decode paths (encode_value/intern/dict/packed/table/
 * assoc/object/slots/enum/magic/decode_value/dict-header/caches) stay
 * static in phpser.c so no cross-TU boundary can cost inlining on the
 * hot path. Never installed (see config.m4 PHP_INSTALL_HEADERS).
 */
#ifndef PHP_PHPSER_INT_H
#define PHP_PHPSER_INT_H

#include "php.h"
#include "php_phpser.h"
#include "ext/hash/php_hash.h"

/* SHA256 output size; the signed-payload tag length. */
#define PHPSER_HMAC_TAG_LEN 32

/* Session encode-failure tombstone (CR-006). Returned instead of NULL when
 * $_SESSION can't be encoded (over-depth, over-size, hook threw): the engine
 * persists an empty string for NULL, which the next request would read as a
 * brand-new SUCCESS-empty session — silent data loss. This marker is
 * deliberately not a valid frame (first byte is no wire version), so the
 * next read fails loudly via the decode-FAILURE path. */
#define PHPSER_SESSION_TOMBSTONE "phpser:session-not-serialized"

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

/* Cached at MINIT (defined in phpser_hmac.c). ext/hash is mandatory since
 * PHP 7.4 and lookup never fails for a builtin algo; callers still
 * null-check defensively. */
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
