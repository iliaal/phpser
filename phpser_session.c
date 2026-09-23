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
 * Session serializer integration. Gated on HAVE_PHP_SESSION (set by config.m4
 * when phpize detects the session extension). Compiled out under the local
 * dev Makefile, which doesn't define the macro.
 * ------------------------------------------------------------------------- */

#include "phpser_int.h"

#ifdef HAVE_PHP_SESSION
# include "ext/session/php_session.h"

/* Back-compat wrapper for the session handler, which doesn't take options.
 * Trusted-store assumption: the backend bytes are decoded with no HMAC and
 * ALLOWED_ALL (arbitrary classes instantiate + __wakeup/__unserialize run),
 * so the store must be trusted; app-level signing is a docs-level decision
 * (see SECURITY.md). Exact consumption is enforced: the store framing is
 * authoritative, so trailing bytes are corruption. */
static int phpser_decode_buf(const char *str, size_t str_len, zval *out) {
    return phpser_decode_buf_opts(str, str_len, out, ALLOWED_ALL, NULL, true);
}

PS_SERIALIZER_ENCODE_FUNC(phpser) {
    /* PS(http_session_vars) is the $_SESSION zval reference. Deref before
     * encoding so the wire format stores a plain array, not IS_REFERENCE. */
    zval *session_vars = &PS(http_session_vars);
    if (Z_TYPE_P(session_vars) == IS_REFERENCE) {
        session_vars = Z_REFVAL_P(session_vars);
    }
    /* throw_on_overflow=false: auto-save runs at request shutdown with no
     * execution frame, where an exception becomes an uncaught fatal. Degrade
     * to the E_WARNING session.c uses for write failures. */
    phpser_enc_status status = PHPSER_ENC_OK;
    zend_string *out = phpser_encode_zval_ex(session_vars,
                                             /* throw_on_overflow */ false, &status);
    if (UNEXPECTED(!out)) {
        switch (status) {
        case PHPSER_ENC_SIZE:
            php_error_docref(NULL, E_WARNING,
                "phpser: $_SESSION not serialized — a value exceeds the 4 GiB "
                "wire-format limit");
            break;
        case PHPSER_ENC_EXCEPTION:
            /* Hook exceptions propagate to the save caller; persist nothing. */
            php_error_docref(NULL, E_WARNING,
                "phpser: $_SESSION not serialized — a serialization hook threw");
            return NULL;
        case PHPSER_ENC_DEPTH:
            php_error_docref(NULL, E_WARNING,
                "phpser: $_SESSION not serialized — nesting depth exceeds %d",
                MAX_DEPTH);
            break;
        default:
            php_error_docref(NULL, E_WARNING,
                "phpser: $_SESSION not serialized — encoding failed");
            break;
        }
        /* NULL encodes persist as empty strings, which decode as new sessions.
         * Persist an invalid marker so the next read reports the data loss. */
        return zend_string_init(PHPSER_SESSION_TOMBSTONE,
                               sizeof(PHPSER_SESSION_TOMBSTONE) - 1, 0);
    }
    return out;
}
PS_SERIALIZER_DECODE_FUNC(phpser) {
    zval decoded;
    if (vallen == 0) {
        /* Empty storage represents a new session; it has no wire version byte. */
        array_init(&decoded);
    } else if (phpser_decode_buf(val, vallen, &decoded) < 0) {
        return FAILURE;
    } else if (Z_TYPE(decoded) != IS_ARRAY) {
        /* A non-array root cannot populate $_SESSION. */
        zval_ptr_dtor(&decoded);
        return FAILURE;
    }
    if (!Z_ISUNDEF(PS(http_session_vars))) {
        zval_ptr_dtor(&PS(http_session_vars));
    }
    ZVAL_NEW_REF(&PS(http_session_vars), &decoded);
    Z_ADDREF_P(&PS(http_session_vars));
    /* Keep the userland $_SESSION symbol bound to the new session reference. */
    zend_string *var_name = ZSTR_INIT_LITERAL("_SESSION", 0);
    zend_hash_update_ind(&EG(symbol_table), var_name, &PS(http_session_vars));
    zend_string_release_ex(var_name, 0);
    return SUCCESS;
}
#endif /* HAVE_PHP_SESSION */
