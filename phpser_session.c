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

#define PHPSER_SESSION_KDF_LABEL "phpser:session:v1"

#define PHPSER_SESSION_KEY_MIN 32

typedef enum {
    PHPSER_SKEY_UNSET = 0,
    PHPSER_SKEY_OK,
    PHPSER_SKEY_SHORT,
} phpser_skey_status;

static phpser_skey_status phpser_session_key_lookup(zend_string **out) {
    /* zend_ini_get_value() is the lookup that exists on PHP 8.2 through 8.6;
     * zend_ini_str_ex() is 8.3+ and INI_STR() is gone in 8.6. */
    zend_string *name = ZSTR_INIT_LITERAL("phpser.session_key", 0);
    zend_string *key = zend_ini_get_value(name);
    zend_string_release_ex(name, 0);
    *out = NULL;
    if (!key || ZSTR_LEN(key) == 0) {
        return PHPSER_SKEY_UNSET;
    }
    if (ZSTR_LEN(key) < PHPSER_SESSION_KEY_MIN) {
        return PHPSER_SKEY_SHORT;
    }
    *out = key;
    return PHPSER_SKEY_OK;
}

/* With a key set, signing is mandatory in both directions, so an unsigned
 * blob can never be read back. A short key fails closed rather than falling
 * back to unsigned: INI booleans turn `On` into the one-byte key "1". */
static phpser_skey_status phpser_session_key(zend_string **out) {
    phpser_skey_status st = phpser_session_key_lookup(out);
    if (UNEXPECTED(st == PHPSER_SKEY_SHORT)) {
        php_error_docref(NULL, E_WARNING,
            "phpser.session_key must be at least %d bytes; session data rejected",
            PHPSER_SESSION_KEY_MIN);
    }
    return st;
}

const char *phpser_session_signing_status(void) {
    zend_string *key;
    switch (phpser_session_key_lookup(&key)) {
    case PHPSER_SKEY_OK:
        return "enabled";
    case PHPSER_SKEY_SHORT:
        return "invalid (phpser.session_key shorter than 32 bytes; sessions rejected)";
    default:
        return "disabled";
    }
}

/* Tag over the frame under a per-session-ID subkey. Binding the ID stops a
 * store writer from copying another user's validly signed blob into their
 * own session file; the label keeps session tags distinct from
 * phpser_serialize_signed() tags under the same key. */
static int phpser_session_tag(const zend_string *key, const char *frame, size_t frame_len,
                              unsigned char tag[PHPSER_HMAC_TAG_LEN]) {
    zend_string *sid = PS(id);
    if (UNEXPECTED(!sid)) {
        return -1;
    }
    size_t label_len = sizeof(PHPSER_SESSION_KDF_LABEL);
    size_t msg_len = label_len + ZSTR_LEN(sid);
    unsigned char *msg = emalloc(msg_len);
    memcpy(msg, PHPSER_SESSION_KDF_LABEL, label_len);
    memcpy(msg + label_len, ZSTR_VAL(sid), ZSTR_LEN(sid));

    unsigned char subkey[PHPSER_HMAC_TAG_LEN];
    int rc = phpser_hmac_sha256((const unsigned char *) ZSTR_VAL(key), ZSTR_LEN(key),
                                msg, msg_len, subkey);
    efree(msg);
    if (rc == 0) {
        rc = phpser_hmac_sha256(subkey, sizeof(subkey),
                                (const unsigned char *) frame, frame_len, tag);
    }
    ZEND_SECURE_ZERO(subkey, sizeof(subkey));
    return rc;
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
    zend_string *key;
    if (UNEXPECTED(phpser_session_key(&key) == PHPSER_SKEY_SHORT)) {
        return zend_string_init(PHPSER_SESSION_TOMBSTONE,
                               sizeof(PHPSER_SESSION_TOMBSTONE) - 1, 0);
    }
    phpser_enc_status status = PHPSER_ENC_OK;
    zend_string *out = phpser_encode_zval_ex(session_vars,
                                             /* throw_on_overflow */ false, &status,
                                             key ? PHPSER_HMAC_TAG_LEN : 0);
    if (out && key) {
        unsigned char *tail = (unsigned char *) ZSTR_VAL(out) + ZSTR_LEN(out);
        if (UNEXPECTED(phpser_session_tag(key, ZSTR_VAL(out), ZSTR_LEN(out), tail) != 0)) {
            php_error_docref(NULL, E_WARNING,
                "phpser: $_SESSION not serialized — no active session ID to sign for");
            zend_string_release(out);
            return zend_string_init(PHPSER_SESSION_TOMBSTONE,
                                   sizeof(PHPSER_SESSION_TOMBSTONE) - 1, 0);
        }
        ZSTR_LEN(out) += PHPSER_HMAC_TAG_LEN;
        ZSTR_VAL(out)[ZSTR_LEN(out)] = '\0';
    }
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
    zend_string *key;
    if (UNEXPECTED(phpser_session_key(&key) == PHPSER_SKEY_SHORT)) {
        return FAILURE;
    }
    if (key && vallen > 0) {
        unsigned char tag[PHPSER_HMAC_TAG_LEN];
        if (vallen == sizeof(PHPSER_SESSION_TOMBSTONE) - 1
            && memcmp(val, PHPSER_SESSION_TOMBSTONE, vallen) == 0) {
            php_error_docref(NULL, E_WARNING,
                "phpser: session data is the encode-failure marker, not decoded");
            return FAILURE;
        }
        if (vallen <= PHPSER_HMAC_TAG_LEN
            || phpser_session_tag(key, val, vallen - PHPSER_HMAC_TAG_LEN, tag) != 0
            || !phpser_ct_eq(tag, (const unsigned char *) val + vallen - PHPSER_HMAC_TAG_LEN,
                             PHPSER_HMAC_TAG_LEN)) {
            php_error_docref(NULL, E_WARNING,
                "phpser: session data failed HMAC verification, not decoded");
            return FAILURE;
        }
        vallen -= PHPSER_HMAC_TAG_LEN;
    }
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
