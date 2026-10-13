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
 * A consumer includes this header, declares ZEND_MOD_REQUIRED("phpser") (or
 * ZEND_MOD_OPTIONAL plus a runtime check) so phpser loads first, and calls
 * the functions below during a request. On Windows, define nothing and
 * link against php_phpser.lib.
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
 *   unsigned or foreign input is rejected with an E_WARNING.
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

/* Serializes `value` into a new non-persistent string stored in *out.
 * Returns 0 on success. Returns -1 on failure with *out set to NULL; an
 * exception may be pending (a serialization hook threw, or the value
 * exceeds the nesting or size limit), or an E_WARNING was raised. */
PHP_PHPSER_API int phpser_api_serialize(const zval *value,
                                        const char *context, size_t context_len,
                                        zend_string **out);

/* Decodes `buf` into `out`. Trailing bytes after the value are rejected.
 * Returns 0 on success. Returns -1 on failure with `out` set to NULL, never
 * a partial value; an exception thrown by a decode hook may be pending.
 * `buf` must stay unchanged for the duration of the call. */
PHP_PHPSER_API int phpser_api_unserialize(const char *buf, size_t buf_len,
                                          const char *context, size_t context_len,
                                          zval *out);

END_EXTERN_C()

#endif /* PHP_PHPSER_API_H */
