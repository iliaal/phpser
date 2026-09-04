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
 * phpser_module.c — cold userland entry points and module plumbing.
 *
 * Holds the PHP_FUNCTION wrappers, the allowed_classes option parser, and
 * MINIT/MSHUTDOWN/RINIT/MINFO plus the module entry. All hot encode/decode
 * work stays static in phpser.c; this TU only frames the calls.
 */

#include "phpser_int.h"
#include "ext/standard/info.h"
#include "Zend/zend_exceptions.h"

#ifdef HAVE_PHP_SESSION
# include "ext/session/php_session.h"
#endif

PHP_FUNCTION(phpser_serialize) {
    zval *value;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(value)
    ZEND_PARSE_PARAMETERS_END();
    zend_string *out = phpser_encode_zval(value, /* throw_on_overflow */ true);
    if (UNEXPECTED(!out)) {
        /* Encode failed — depth cap, >4GiB string, or a hook threw — with
         * the exception already pending. */
        RETURN_THROWS();
    }
    RETVAL_STR(out);
}

/* Parse a phpser_unserialize options array. On success, *out_set may be
 * non-NULL and the caller must free it. Returns -1 on type error (an
 * exception is already thrown). param_idx is the arg position for the
 * error message (2 for unserialize, 3 for unserialize_signed); fname is
 * the calling function's name for the TypeError text. */
static int parse_unserialize_options(
    HashTable *options_ht, int param_idx, const char *fname,
    int *out_mode, HashTable **out_set)
{
    *out_mode = ALLOWED_ALL;
    *out_set = NULL;
    if (!options_ht) return 0;
    zval *ac = zend_hash_str_find_deref(options_ht, "allowed_classes",
                                         sizeof("allowed_classes") - 1);
    if (!ac) return 0;
    if (Z_TYPE_P(ac) == IS_FALSE) { *out_mode = ALLOWED_NONE; return 0; }
    if (Z_TYPE_P(ac) == IS_TRUE)  { *out_mode = ALLOWED_ALL;  return 0; }
    if (Z_TYPE_P(ac) == IS_ARRAY) {
        /* Build a lowercased-name lookup set. PHP class names are
         * case-insensitive; storing pre-lowered keeps the per-object
         * filter check to one zend_hash_exists. */
        *out_mode = ALLOWED_SET;
        *out_set = emalloc(sizeof(HashTable));
        zend_hash_init(*out_set, zend_hash_num_elements(Z_ARRVAL_P(ac)),
                       NULL, NULL, 0);
        zval *cn;
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ac), cn) {
            ZVAL_DEREF(cn);
            if (Z_TYPE_P(cn) != IS_STRING) {
                /* Match PHP's native unserialize: non-string entry in
                 * the allowed_classes array is a TypeError. Silently
                 * skipping would let a misconfigured allowlist pass
                 * unflagged. */
                zend_hash_destroy(*out_set);
                efree(*out_set);
                *out_set = NULL;
                zend_type_error(
                    "%s(): allowed_classes option must "
                    "be an array of class names, %s given",
                    fname,
#if PHP_VERSION_ID >= 80300
                    zend_zval_value_name(cn));
#else
                    zend_zval_type_name(cn));
#endif
                return -1;
            }
            zend_string *lc = zend_string_tolower(Z_STR_P(cn));
            zval one; ZVAL_TRUE(&one);
            zend_hash_add(*out_set, lc, &one);
            zend_string_release(lc);
        } ZEND_HASH_FOREACH_END();
        return 0;
    }
    /* Callers return immediately on -1, but leave the out-param determinate
     * so a future caller can't read an uninitialized set pointer. */
    *out_set = NULL;
    zend_argument_value_error(param_idx,
        "allowed_classes option must be array or bool");
    return -1;
}

PHP_FUNCTION(phpser_unserialize) {
    char *str;
    size_t str_len;
    HashTable *options_ht = NULL;
    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STRING(str, str_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_HT(options_ht)
    ZEND_PARSE_PARAMETERS_END();

    int allowed_mode;
    HashTable *allowed_set;
    if (parse_unserialize_options(options_ht, 2, "phpser_unserialize",
                                  &allowed_mode, &allowed_set) < 0) {
        RETURN_THROWS();
    }

    /* Unsigned stays lenient on trailing bytes (historical behavior). */
    phpser_decode_buf_opts(str, str_len, return_value, allowed_mode, allowed_set,
                           /*require_exact*/ false);

    if (allowed_set) {
        zend_hash_destroy(allowed_set);
        efree(allowed_set);
    }
}

PHP_FUNCTION(phpser_serialize_signed) {
    zval *value;
    char *key;
    size_t key_len;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ZVAL(value)
        Z_PARAM_STRING(key, key_len)
    ZEND_PARSE_PARAMETERS_END();

    /* An empty key reduces HMAC to a fixed, keyless tag anyone can compute,
     * silently downgrading the signed path to forgeable. Reject loudly rather
     * than emit an unprotected payload. */
    if (key_len == 0) {
        zend_throw_exception(zend_ce_exception,
            "phpser: signing key must not be empty", 0);
        RETURN_THROWS();
    }

    zend_string *frame = phpser_encode_zval(value, /* throw_on_overflow */ true);
    if (UNEXPECTED(!frame)) {
        /* Encode failed — depth cap, >4GiB string, or a hook threw — with
         * the exception already pending. */
        RETURN_THROWS();
    }
    /* Reallocate to add tag space. zend_string_extend grows the underlying
     * allocation and bumps ZSTR_LEN. The 32 trailing bytes become the HMAC. */
    size_t frame_len = ZSTR_LEN(frame);
    zend_string *signed_str = zend_string_extend(frame, frame_len + PHPSER_HMAC_TAG_LEN, 0);
    unsigned char *tag = (unsigned char *)ZSTR_VAL(signed_str) + frame_len;
    int hrc = phpser_hmac_sha256(
            (const unsigned char *)key, key_len,
            (const unsigned char *)ZSTR_VAL(signed_str), frame_len,
            tag);
    if (hrc < 0) {
        zend_string_release(signed_str);
        zend_throw_exception(zend_ce_exception, hrc == -2
            ? "phpser: unsupported SHA256 block size"
            : "phpser: SHA256 hash ops unavailable (ext/hash not loaded?)", 0);
        RETURN_THROWS();
    }
    ZSTR_VAL(signed_str)[frame_len + PHPSER_HMAC_TAG_LEN] = '\0';
    RETURN_STR(signed_str);
}

PHP_FUNCTION(phpser_unserialize_signed) {
    char *payload;
    size_t payload_len;
    char *key;
    size_t key_len;
    HashTable *options_ht = NULL;
    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STRING(payload, payload_len)
        Z_PARAM_STRING(key, key_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_HT(options_ht)
    ZEND_PARSE_PARAMETERS_END();

    /* An empty key makes the HMAC keyless and forgeable; reject before any
     * verify work so a misconfigured caller fails loud instead of accepting
     * attacker-signed bytes. Matches the serialize_signed guard. */
    if (key_len == 0) {
        zend_throw_exception(zend_ce_exception,
            "phpser: signing key must not be empty", 0);
        RETURN_THROWS();
    }

    /* Payload must include at least the 32-byte tag. Anything shorter is
     * either truncated or never signed — reject without leaking which. */
    if (payload_len < PHPSER_HMAC_TAG_LEN) {
        zend_throw_exception(zend_ce_exception,
            "phpser: signed payload too short", 0);
        RETURN_THROWS();
    }
    size_t frame_len = payload_len - PHPSER_HMAC_TAG_LEN;
    unsigned char expected[PHPSER_HMAC_TAG_LEN];
    int vrc = phpser_hmac_sha256(
            (const unsigned char *)key, key_len,
            (const unsigned char *)payload, frame_len,
            expected);
    if (vrc < 0) {
        zend_throw_exception(zend_ce_exception, vrc == -2
            ? "phpser: unsupported SHA256 block size"
            : "phpser: SHA256 hash ops unavailable (ext/hash not loaded?)", 0);
        RETURN_THROWS();
    }
    if (!phpser_ct_eq(expected,
                      (const unsigned char *)payload + frame_len,
                      PHPSER_HMAC_TAG_LEN)) {
        zend_throw_exception(zend_ce_exception,
            "phpser: signature verification failed", 0);
        RETURN_THROWS();
    }

    int allowed_mode;
    HashTable *allowed_set;
    if (parse_unserialize_options(options_ht, 3, "phpser_unserialize_signed",
                                  &allowed_mode, &allowed_set) < 0) {
        RETURN_THROWS();
    }

    int rc = phpser_decode_buf_opts(payload, frame_len, return_value, allowed_mode,
                                    allowed_set, /*require_exact*/ true);

    if (allowed_set) {
        zend_hash_destroy(allowed_set);
        efree(allowed_set);
    }

    /* A valid HMAC over a body that then fails to decode (corruption, or a
     * class the payload needs was removed since it was signed) is an error,
     * not data — throw rather than return a silent null the caller can't
     * distinguish from a legitimately-signed null (which decodes as rc==0).
     * Mirrors the signature-failure throw above. If the decode already left an
     * exception pending (e.g. a __wakeup hook threw), let that propagate. */
    if (rc < 0) {
        if (!EG(exception)) {
            zend_throw_exception(zend_ce_exception,
                "phpser: signed payload failed to decode", 0);
        }
        RETURN_THROWS();
    }
}

/* -------------------------------------------------------------------------
 * Module plumbing.
 * ------------------------------------------------------------------------- */

#include "phpser_arginfo.h"

static PHP_MINIT_FUNCTION(phpser) {
#if (defined(COMPILE_DL_PHPSER) || defined(ZEND_COMPILE_DL_EXT)) && defined(ZTS)
    /* Populate our TLS slot from the host PHP's thread-local state pointer.
     * Required for dynamically-loaded extensions under ZTS — otherwise
     * macros that touch CG/EG via the TSRMLS cache crash on lookup.
     *
     * The OR covers both build paths: phpize-generated config.m4 defines
     * COMPILE_DL_<EXTNAME>; the hand-rolled Makefile defines the generic
     * ZEND_COMPILE_DL_EXT. Without this widening, a ZTS build via the
     * Makefile path would compile but crash on first CG/EG access. */
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
#ifdef HAVE_PHP_SESSION
    /* Serializer entry points live in phpser_session.c; declare them here
     * for the registration call below. */
    PS_SERIALIZER_FUNCS(phpser);
    /* Register session.serialize_handler = phpser. Best-effort: the session
     * extension may not be loaded (rare in shared-build setups), and we
     * tolerate that case silently. */
    php_session_register_serializer(
        PHP_PHPSER_EXTNAME,
        PS_SERIALIZER_ENCODE_NAME(phpser),
        PS_SERIALIZER_DECODE_NAME(phpser));
#endif
    /* Cache SHA256 ops for HMAC signing. ext/hash is mandatory since PHP
     * 7.4 so this never fails in normal builds; we still null-check at
     * call time. php_hash_fetch_ops only reads the algo name for the table
     * lookup — it doesn't retain the pointer — so the lookup zend_string is
     * a transient stack-local released immediately, not a module global. */
    zend_string *algo = zend_string_init("sha256", sizeof("sha256") - 1, 0);
    phpser_sha256_ops = php_hash_fetch_ops(algo);
    zend_string_release(algo);
    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(phpser) {
    phpser_sha256_ops = NULL;
    return SUCCESS;
}

#if (defined(COMPILE_DL_PHPSER) || defined(ZEND_COMPILE_DL_EXT)) && defined(ZTS)
/* Refresh this thread's TLS-cache slot every request. MINIT's
 * ZEND_TSRMLS_CACHE_UPDATE() only populates the cache on the thread that
 * loaded the module; under a threaded ZTS SAPI (Windows ships TS builds)
 * worker threads run RINIT, not MINIT, and would otherwise touch CG/EG
 * through an unpopulated cache and crash. Mirrors the canonical ext_skel
 * skeleton and the widened DL guard used in MINIT / get_module().
 *
 * The whole function — and its module-entry slot below — is compiled out on
 * NTS builds (php-fpm and friends), so those register no RINIT and pay zero
 * per-request cost. Only threaded ZTS DL builds, which actually need the
 * per-thread refresh, carry it. */
static PHP_RINIT_FUNCTION(phpser) {
    ZEND_TSRMLS_CACHE_UPDATE();
    return SUCCESS;
}
#endif

static PHP_MINFO_FUNCTION(phpser) {
    php_info_print_table_start();
    php_info_print_table_row(2, "phpser support", "enabled");
    php_info_print_table_row(2, "version", PHP_PHPSER_VERSION);
#ifdef HAVE_PHP_SESSION
    php_info_print_table_row(2, "session.serialize_handler", "available");
#else
    php_info_print_table_row(2, "session.serialize_handler", "disabled (compiled without session)");
#endif
    php_info_print_table_end();
}

/* Declare session as an OPTIONAL dependency. The runtime declaration
 * (vs. only config.m4's PHP_ADD_EXTENSION_DEP) is what controls MINIT
 * ordering — without it, alphabetical conf.d load order can put our
 * MINIT before session's, and php_session_register_serializer runs
 * against a session module that isn't ready yet.
 * See ~/ai/wiki/architecture/php-extension-c-conventions.md "Cross-extension
 * class lookup at MINIT" for the failure mode. */
static const zend_module_dep phpser_deps[] = {
    /* hash is mandatory since PHP 7.4 — we use its SHA256 ops for the
     * signed-payload HMAC. ZEND_MOD_REQUIRED forces the engine to load
     * hash's MINIT before ours so phpser_sha256_ops resolves cleanly. */
    ZEND_MOD_REQUIRED("hash")
#ifdef HAVE_PHP_SESSION
    ZEND_MOD_OPTIONAL("session")
#endif
    ZEND_MOD_END
};

zend_module_entry phpser_module_entry = {
    STANDARD_MODULE_HEADER_EX,
    NULL,
    phpser_deps,
    PHP_PHPSER_EXTNAME,
    ext_functions,
    PHP_MINIT(phpser),
    PHP_MSHUTDOWN(phpser),
#if (defined(COMPILE_DL_PHPSER) || defined(ZEND_COMPILE_DL_EXT)) && defined(ZTS)
    PHP_RINIT(phpser), NULL,
#else
    NULL, NULL,   /* no RINIT on NTS builds: zero per-request cost */
#endif
    PHP_MINFO(phpser),
    PHP_PHPSER_VERSION,
    STANDARD_MODULE_PROPERTIES,
};

/* Under ZTS, a dynamically-loaded extension needs its own TLS slot cache
 * because the host PHP's per-thread state pointer isn't accessible
 * through static linkage. Config defines ZEND_ENABLE_STATIC_TSRMLS_CACHE;
 * the matching cache_define + cache_update at MINIT completes the wiring.
 * On NTS builds both macros expand to nothing. */
ZEND_TSRMLS_CACHE_DEFINE()

/* get_module() is the dynamic-loader entry point; emit it only for a shared
 * build. A hypothetical static link into the PHP binary would otherwise get
 * a duplicate/clashing symbol. The OR mirrors the MINIT TSRMLS guard so the
 * hand-rolled dev Makefile (which defines ZEND_COMPILE_DL_EXT rather than
 * COMPILE_DL_PHPSER) still produces a loadable .so for `make test`. */
#if defined(COMPILE_DL_PHPSER) || defined(ZEND_COMPILE_DL_EXT)
ZEND_GET_MODULE(phpser)
#endif
