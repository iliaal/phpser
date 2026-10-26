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

/* PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 queue a memory_limit error instead of
 * bailing out; the allocation still succeeds until a hard limit (2x the soft
 * limit below 1G) that _exit()s the process. Loops that keep allocating must
 * stop on their own once the error is pending. */
#ifdef ZEND_HAS_OOM
# define PHPSER_HAS_OOM() ZEND_HAS_OOM()
#else
# define PHPSER_HAS_OOM() 0
#endif

/* A failed encode, signed decode, or keyed decode has an exception or a
 * queued memory_limit error pending; RETURN_THROWS() asserts the former. */
#define PHPSER_RETURN_FAILED() do { \
        if (PHPSER_HAS_OOM()) { (void) return_value; return; } \
        RETURN_THROWS(); \
    } while (0)

/* SHA256 output size; the signed-payload tag length. */
#define PHPSER_HMAC_TAG_LEN 32

/* Depth/size failures persist an invalid frame so the next session read
 * reports failure instead of treating an empty string as a new session. */
#define PHPSER_SESSION_TOMBSTONE "phpser:session-not-serialized"

/* "enabled", "disabled", or an "invalid ..." explanation for MINFO.
 * Defined only when HAVE_PHP_SESSION. (phpser_session.c) */
const char *phpser_session_signing_status(void);

/* Keyed (phpser.key) signing shared by the C API, the APCu serializer, and
 * the userland keyed functions. (phpser_api.c) */
#define PHPSER_KEY_MIN 32
#define PHPSER_KEYED_CONTEXT_MAX 255

typedef enum {
    PHPSER_KEY_UNSET = 0,
    PHPSER_KEY_OK,
    PHPSER_KEY_SHORT,
} phpser_key_status;

/* Reads phpser.key. *out is set only for PHPSER_KEY_OK and stays owned by
 * the INI entry. */
phpser_key_status phpser_key_lookup(zend_string **out);

/* 1 to PHPSER_KEYED_CONTEXT_MAX bytes, no NUL, not "session". */
bool phpser_keyed_context_valid(const char *context, size_t context_len);

/* Encodes `value`; with a non-NULL key, appends the context-bound tag.
 * Returns NULL on failure: an exception is pending unless the SHA-256 ops
 * are unavailable. */
zend_string *phpser_keyed_encode(zval *value, const zend_string *key,
                                 const char *context, size_t context_len);

typedef enum {
    PHPSER_KEYED_OK = 0,
    PHPSER_KEYED_BAD_TAG,
    PHPSER_KEYED_DECODE,
} phpser_keyed_rc;

/* With a non-NULL key, checks the trailing tag in constant time and decodes
 * the frame before it only when the tag matches; with a NULL key, decodes
 * `buf` as a plain frame. Trailing bytes after the value are rejected. On
 * failure `out` is NULL; PHPSER_KEYED_DECODE may leave a hook exception
 * pending. */
phpser_keyed_rc phpser_keyed_decode(const char *buf, size_t buf_len, const zend_string *key,
                                    const char *context, size_t context_len, zval *out,
                                    int allowed_mode, HashTable *allowed_set);

/* Wipes the calling thread's cached derived subkey. Call from MSHUTDOWN.
 * (phpser_api.c) */
void phpser_api_mshutdown(void);

/* MINFO strings. (phpser_api.c) */
const char *phpser_key_signing_status(void);
const char *phpser_apcu_status(void);

/* Resets the APCu status, then, for a persistent (not dl()) load, registers
 * the "phpser" APCu serializer when APCu exposes its registration hook.
 * Call from MINIT, after APCu's MINIT. (phpser_api.c) */
void phpser_apcu_minit(bool persistent);

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
 * Returns 0 on success, -1 if SHA256 ops weren't resolved at MINIT.
 * (phpser_hmac.c) */
int phpser_hmac_sha256(
    const unsigned char *key, size_t key_len,
    const unsigned char *data, size_t data_len,
    unsigned char out[PHPSER_HMAC_TAG_LEN]);

/* Wipes the calling thread's cached HMAC key midstates. Call from
 * MSHUTDOWN; ZTS worker threads that exit earlier release theirs unwiped.
 * (phpser_hmac.c) */
void phpser_hmac_mshutdown(void);

/* SHA-256 backend the HMAC selects on this CPU: "armv8", "sha-ni", or
 * "ext/hash (not compiled)" / "ext/hash (cpu lacks feature)" /
 * "ext/hash (self-test failed)". Reruns detection and the self-test.
 * (phpser_sha256.c) */
const char *phpser_sha256_backend(void);

/* Constant-time byte compare. Returns 1 if all `n` bytes are equal.
 * (phpser_hmac.c) */
int phpser_ct_eq(const unsigned char *a, const unsigned char *b, size_t n);

/* Reusable encode: produce a framed payload zend_string from a zval.
 * Caller owns the returned zend_string. Returns NULL when a non-serializable
 * object is rejected, a hook throws or cleanup raises, or when depth/size
 * limits would make the frame undecodable. With throw_on_overflow, the userland entry points turn limit
 * failures into exceptions; the session handler passes false and degrades
 * them to warnings, because request-shutdown auto-save may have no
 * execution frame to catch one.
 *
 * The result is exactly sized: ZSTR_LEN is the frame length, and the
 * allocation has tail_reserve writable bytes starting at ZSTR_VAL + ZSTR_LEN
 * (where the frame's NUL sits), plus one more byte for a terminator after
 * them. A caller that appends a trailer (the signed entry point's HMAC tag)
 * writes it there, then raises ZSTR_LEN by tail_reserve and re-terminates,
 * avoiding a realloc. Pass 0 when nothing is appended. (phpser.c) */
zend_string *phpser_encode_zval_ex(zval *value, bool throw_on_overflow,
                                   phpser_enc_status *status, size_t tail_reserve);
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
