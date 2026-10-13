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
 * phpser C API for other extensions (cache clients, session stores).
 *
 * A consumer includes this header, declares ZEND_MOD_REQUIRED("phpser") in
 * its module dependencies so phpser loads and initializes first (the model
 * igbinary uses for phpredis and memcached), and calls the functions below
 * during a request. The consumer references these symbols directly, so its
 * shared object needs phpser loaded to resolve them; on Windows, link
 * against php_phpser.lib and define nothing. To reject an incompatible
 * phpser at startup, compare phpser_api_version() with PHPSER_API_VERSION
 * in MINIT.
 *
 * Both functions follow the phpser.key INI setting:
 *
 * - Unset or empty: plain phpser frames, byte-identical to
 *   phpser_serialize(). The store is trusted: decoding instantiates any
 *   class and runs __wakeup / __unserialize.
 * - 32 bytes or longer: the output is frame || 32-byte HMAC-SHA256 tag.
 *   The tag key is HMAC-SHA256(phpser.key, "phpser:" || context ||
 *   ":v1\0"), so a value signed for one context does not verify under
 *   another. The tag is checked in constant time before any decode work;
 *   unsigned or foreign input fails with PHPSER_API_ERR_TAG and an
 *   E_WARNING, as the signed session handler does.
 * - Shorter than 32 bytes: both directions fail with an E_WARNING naming
 *   phpser.key. Nothing is signed with a weak key and nothing falls back
 *   to unsigned.
 *
 * The context names the store ("redis", "memcached"). It must be 1 to 255
 * bytes, contain no NUL byte, and not be "session" (reserved for the session
 * handler's derivation). Signing binds a value to the key and context, not
 * to the cache entry name it is stored under.
 */
#ifndef PHP_PHPSER_API_H
#define PHP_PHPSER_API_H

#include "php.h"

/* Bumped on any incompatible change to the functions below. */
#define PHPSER_API_VERSION 1

/* Return codes. Consumers that fall back to raw bytes when a value does not
 * decode should not do so for PHPSER_API_ERR_TAG: that value failed
 * verification and must be treated as absent. */
#define PHPSER_API_OK       0
#define PHPSER_API_ERR      (-1) /* encode/decode failure, bad context, short key */
#define PHPSER_API_ERR_TAG  (-2) /* phpser.key is set and the HMAC tag did not verify */

#ifdef PHP_WIN32
# ifdef PHPSER_EXPORTS
#  define PHP_PHPSER_API __declspec(dllexport)
# else
#  define PHP_PHPSER_API __declspec(dllimport)
# endif
#elif defined(__GNUC__) && __GNUC__ >= 4
# define PHP_PHPSER_API __attribute__((visibility("default")))
#else
# define PHP_PHPSER_API
#endif

BEGIN_EXTERN_C()

/* PHPSER_API_VERSION of the loaded phpser. */
PHP_PHPSER_API unsigned int phpser_api_version(void);

/* Serializes `value`. On PHPSER_API_OK, *out is a new non-persistent string
 * owned by the caller, who releases it with zend_string_release(). On
 * PHPSER_API_ERR, *out is NULL and either an exception is pending (a
 * serialization hook threw, or the value exceeds the nesting or 4 GiB size
 * limit) or an E_WARNING was raised (bad context, short key). */
PHP_PHPSER_API int phpser_api_serialize(const zval *value,
                                        const char *context, size_t context_len,
                                        zend_string **out);

/* Decodes `buf` into `out`, which must not hold a live value on entry: it
 * is overwritten without being released. On PHPSER_API_OK, `out` holds the
 * decoded value, owned by the caller (release with zval_ptr_dtor()). On
 * failure `out` is IS_NULL, never a partial value: PHPSER_API_ERR_TAG means
 * verification failed (E_WARNING raised, nothing decoded); PHPSER_API_ERR
 * means a bad context, a short key (E_WARNING raised), or a frame that does
 * not decode, possibly with an exception from a decode hook pending.
 * Trailing bytes after the value are rejected. `buf` must stay unchanged
 * for the duration of the call. */
PHP_PHPSER_API int phpser_api_unserialize(const char *buf, size_t buf_len,
                                          const char *context, size_t context_len,
                                          zval *out);

END_EXTERN_C()

#endif /* PHP_PHPSER_API_H */
