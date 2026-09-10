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
        /* PHP class names are case-insensitive. */
        *out_mode = ALLOWED_SET;
        *out_set = emalloc(sizeof(HashTable));
        zend_hash_init(*out_set, zend_hash_num_elements(Z_ARRVAL_P(ac)),
                       NULL, NULL, 0);
        zval *cn;
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ac), cn) {
            ZVAL_DEREF(cn);
            if (Z_TYPE_P(cn) != IS_STRING) {
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

    /* An empty key makes HMAC forgeable. */
    if (key_len == 0) {
        zend_throw_exception(zend_ce_exception,
            "phpser: signing key must not be empty", 0);
        RETURN_THROWS();
    }

    zend_string *frame = phpser_encode_zval(value, /* throw_on_overflow */ true);
    if (UNEXPECTED(!frame)) {
        RETURN_THROWS();
    }
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

    /* An empty key makes HMAC forgeable. */
    if (key_len == 0) {
        zend_throw_exception(zend_ce_exception,
            "phpser: signing key must not be empty", 0);
        RETURN_THROWS();
    }

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

    /* Distinguish malformed signed data from a valid signed null. Preserve
     * exceptions already raised by decode hooks. */
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
    /* Initialize TLS for shared ZTS builds. phpize defines COMPILE_DL_PHPSER;
     * the in-tree Makefile defines ZEND_COMPILE_DL_EXT. */
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
#ifdef HAVE_PHP_SESSION
    PS_SERIALIZER_FUNCS(phpser);
    /* Register session.serialize_handler = phpser. Best-effort: the session
     * extension may not be loaded (rare in shared-build setups), and we
     * tolerate that case silently. */
    php_session_register_serializer(
        PHP_PHPSER_EXTNAME,
        PS_SERIALIZER_ENCODE_NAME(phpser),
        PS_SERIALIZER_DECODE_NAME(phpser));
#endif
    /* php_hash_fetch_ops does not retain the name; release it after lookup. */
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
/* MINIT initializes only the loading thread; each ZTS worker needs its own
 * TLS cache initialized before CG/EG access. */
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

/* Runtime dependencies order MINIT; config.m4 dependencies alone do not.
 * Session must initialize before we register its serializer. */
static const zend_module_dep phpser_deps[] = {
    /* Resolve hash's MINIT before fetching SHA256 ops. */
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

/* Shared ZTS builds need an extension-local TLS cache. */
ZEND_TSRMLS_CACHE_DEFINE()

/* Both shared-build paths need get_module; static builds must omit it. */
#if defined(COMPILE_DL_PHPSER) || defined(ZEND_COMPILE_DL_EXT)
ZEND_GET_MODULE(phpser)
#endif
