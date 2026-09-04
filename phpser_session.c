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
 * (see SECURITY.md). Exact consumption IS enforced here (CR-002): the store
 * framing is authoritative, so a suffix is corruption, not data. */
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
    /* throw_on_overflow=false: the session auto-save runs at request
     * shutdown with no execution frame, where a thrown exception surfaces as
     * an uncaught fatal the hook can't intercept. So encode reports failure
     * via status without throwing on over-depth; we degrade to the E_WARNING
     * that session.c itself uses for write failures. */
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
            /* A __serialize/__sleep hook in the session graph threw. The
             * exception is pending and propagates to the save call site —
             * already loud — so decline with NULL and persist nothing,
             * matching the pinned "never persist on hook failure" contract.
             * (Depth/size failures below only warn, so they get the
             * tombstone instead.) */
            php_error_docref(NULL, E_WARNING,
                "phpser: $_SESSION not serialized — a serialization hook threw");
            return NULL;
        case PHPSER_ENC_DEPTH:
            php_error_docref(NULL, E_WARNING,
                "phpser: $_SESSION not serialized — nesting depth exceeds %d",
                MAX_DEPTH);
            break;
        default:
            /* Unreachable with the current status enum (OK never returns
             * NULL here; SIZE and EXCEPTION are handled above) — defensive
             * arm so a future status can't silently fall through as depth. */
            php_error_docref(NULL, E_WARNING,
                "phpser: $_SESSION not serialized — encoding failed");
            break;
        }
        /* Tombstone, not NULL: the engine persists an empty string for a
         * NULL encode, and the next request reads that back through the
         * vallen==0 fast path as a brand-new SUCCESS-empty session — the
         * data loss is silent. A distinctive undecodable marker makes the
         * next read fail loudly ("Failed to decode session object")
         * instead. session_encode() surfaces the marker string rather
         * than false so the failure is observable at the call site too. */
        return zend_string_init(PHPSER_SESSION_TOMBSTONE,
                               sizeof(PHPSER_SESSION_TOMBSTONE) - 1, 0);
    }
    return out;
}
PS_SERIALIZER_DECODE_FUNC(phpser) {
    zval decoded;
    if (vallen == 0) {
        /* A brand-new session reads back as an empty string from storage.
         * PHP's native serializers treat that as an empty session; feeding
         * it to the decoder fails the version-byte check (FAILURE), which
         * makes the engine emit "Failed to decode session object" and
         * destroy the session on every first request. Start empty instead. */
        array_init(&decoded);
    } else if (phpser_decode_buf(val, vallen, &decoded) < 0) {
        return FAILURE;
    } else if (Z_TYPE(decoded) != IS_ARRAY) {
        /* A scalar (or object) root can't populate $_SESSION. The old code
         * swapped in [] with SUCCESS, silently coercing the payload into an
         * empty session with no signal. Return FAILURE instead so the engine
         * logs "Failed to decode session object" and starts clean — the
         * coercion is observable rather than silent. */
        zval_ptr_dtor(&decoded);
        return FAILURE;
    }
    if (!Z_ISUNDEF(PS(http_session_vars))) {
        zval_ptr_dtor(&PS(http_session_vars));
    }
    ZVAL_NEW_REF(&PS(http_session_vars), &decoded);
    Z_ADDREF_P(&PS(http_session_vars));
    /* Re-bind the userland $_SESSION symbol to the new reference. Without
     * this, user code reads the previous request's array via the stale
     * symbol-table entry — the PS(http_session_vars) slot is updated but
     * $_SESSION still aliases the old one. PHP's own session decoders
     * (php_serialize, php) do this. See session.c:986 (PS_SERIALIZER_
     * DECODE_FUNC(php_serialize)). */
    zend_string *var_name = ZSTR_INIT_LITERAL("_SESSION", 0);
    zend_hash_update_ind(&EG(symbol_table), var_name, &PS(http_session_vars));
    zend_string_release_ex(var_name, 0);
    return SUCCESS;
}
#endif /* HAVE_PHP_SESSION */
