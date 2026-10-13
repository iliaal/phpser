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
 * phpser.key signing, the exported C API (php_phpser_api.h), and the APCu
 * serializer built on it.
 * ------------------------------------------------------------------------- */

#include "phpser_int.h"
#include "php_phpser_api.h"
#include "zend_atomic.h"

#define PHPSER_KEYED_LABEL_PREFIX "phpser:"
#define PHPSER_KEYED_LABEL_SUFFIX ":v1"

#define PHPSER_APCU_CONTEXT "apcu"

/* The derived subkey of the last (phpser.key, context) pair, per thread.
 * Without it every keyed call pays for two HMAC key schedules plus the
 * derivation, which more than quadruples a small APCu fetch. Keys longer
 * than PHPSER_SUBKEY_CACHE_KEY_MAX bytes are derived on every call. */
#define PHPSER_SUBKEY_CACHE_KEY_MAX 128

typedef struct {
    bool valid;
    size_t key_len;
    size_t context_len;
    unsigned char key[PHPSER_SUBKEY_CACHE_KEY_MAX];
    char context[PHPSER_KEYED_CONTEXT_MAX];
    unsigned char subkey[PHPSER_HMAC_TAG_LEN];
} phpser_subkey_cache;

ZEND_TLS phpser_subkey_cache subkey_cache;

void phpser_api_mshutdown(void) {
    ZEND_SECURE_ZERO(&subkey_cache, sizeof(subkey_cache));
}

/* Called when phpser.key is unset or short, so a key that is no longer
 * configured does not stay resident. */
static zend_always_inline void phpser_subkey_cache_drop(void) {
    if (UNEXPECTED(subkey_cache.valid)) {
        ZEND_SECURE_ZERO(&subkey_cache, sizeof(subkey_cache));
    }
}

phpser_key_status phpser_key_lookup(zend_string **out) {
    /* A direct directive lookup avoids allocating a name per call on the
     * cache hot path; zend_ini_str_ex() is 8.3+ and INI_STR() is gone in 8.6. */
    zend_ini_entry *entry = zend_hash_str_find_ptr(EG(ini_directives),
                                                   "phpser.key", sizeof("phpser.key") - 1);
    zend_string *key = entry ? entry->value : NULL;
    *out = NULL;
    if (!key || ZSTR_LEN(key) == 0) {
        phpser_subkey_cache_drop();
        return PHPSER_KEY_UNSET;
    }
    if (ZSTR_LEN(key) < PHPSER_KEY_MIN) {
        phpser_subkey_cache_drop();
        return PHPSER_KEY_SHORT;
    }
    *out = key;
    return PHPSER_KEY_OK;
}

/* The derivation message is "phpser:" || context || ":v1\0". "session" is
 * refused because the session handler derives from "phpser:session:v1\0" ||
 * session_id, which an empty session ID would make identical. */
bool phpser_keyed_context_valid(const char *context, size_t context_len) {
    return context_len > 0 && context_len <= PHPSER_KEYED_CONTEXT_MAX
        && memchr(context, '\0', context_len) == NULL
        && !(context_len == sizeof("session") - 1
             && memcmp(context, "session", context_len) == 0);
}

static int phpser_keyed_subkey(const zend_string *key, const char *context, size_t context_len,
                               unsigned char subkey[PHPSER_HMAC_TAG_LEN]) {
    phpser_subkey_cache *c = &subkey_cache;
    size_t key_len = ZSTR_LEN(key);
    bool cacheable = key_len <= PHPSER_SUBKEY_CACHE_KEY_MAX;
    ZEND_ASSERT(context_len <= PHPSER_KEYED_CONTEXT_MAX);
    /* Only hit or miss is observable; the key compare has no early exit. */
    if (cacheable && c->valid && c->key_len == key_len && c->context_len == context_len
        && memcmp(c->context, context, context_len) == 0
        && phpser_ct_eq(c->key, (const unsigned char *) ZSTR_VAL(key), key_len)) {
        memcpy(subkey, c->subkey, PHPSER_HMAC_TAG_LEN);
        return 0;
    }
    /* A miss retires the cached key; a shorter or uncacheable new key must not
     * leave any of its bytes behind. */
    if (c->valid) {
        ZEND_SECURE_ZERO(c, sizeof(*c));
    }

    unsigned char msg[sizeof(PHPSER_KEYED_LABEL_PREFIX) - 1 + PHPSER_KEYED_CONTEXT_MAX
                      + sizeof(PHPSER_KEYED_LABEL_SUFFIX)];
    size_t prefix_len = sizeof(PHPSER_KEYED_LABEL_PREFIX) - 1;
    size_t suffix_len = sizeof(PHPSER_KEYED_LABEL_SUFFIX);
    memcpy(msg, PHPSER_KEYED_LABEL_PREFIX, prefix_len);
    memcpy(msg + prefix_len, context, context_len);
    memcpy(msg + prefix_len + context_len, PHPSER_KEYED_LABEL_SUFFIX, suffix_len);
    if (phpser_hmac_sha256((const unsigned char *) ZSTR_VAL(key), key_len,
                           msg, prefix_len + context_len + suffix_len, subkey) != 0) {
        return -1;
    }
    if (cacheable) {
        c->valid = true;
        c->key_len = key_len;
        c->context_len = context_len;
        memcpy(c->key, ZSTR_VAL(key), key_len);
        memcpy(c->context, context, context_len);
        memcpy(c->subkey, subkey, PHPSER_HMAC_TAG_LEN);
    }
    return 0;
}

static int phpser_keyed_tag(const zend_string *key, const char *context, size_t context_len,
                            const char *frame, size_t frame_len,
                            unsigned char tag[PHPSER_HMAC_TAG_LEN]) {
    unsigned char subkey[PHPSER_HMAC_TAG_LEN];
    int rc = phpser_keyed_subkey(key, context, context_len, subkey);
    if (rc == 0) {
        rc = phpser_hmac_sha256(subkey, sizeof(subkey),
                                (const unsigned char *) frame, frame_len, tag);
    }
    ZEND_SECURE_ZERO(subkey, sizeof(subkey));
    return rc;
}

zend_string *phpser_keyed_encode(zval *value, const zend_string *key,
                                 const char *context, size_t context_len) {
    zend_string *out = phpser_encode_zval_ex(value, /* throw_on_overflow */ true, NULL,
                                             key ? PHPSER_HMAC_TAG_LEN : 0);
    if (!out || !key) {
        return out;
    }
    unsigned char *tail = (unsigned char *) ZSTR_VAL(out) + ZSTR_LEN(out);
    if (UNEXPECTED(phpser_keyed_tag(key, context, context_len,
                                    ZSTR_VAL(out), ZSTR_LEN(out), tail) != 0)) {
        zend_string_release(out);
        return NULL;
    }
    ZSTR_LEN(out) += PHPSER_HMAC_TAG_LEN;
    ZSTR_VAL(out)[ZSTR_LEN(out)] = '\0';
    return out;
}

static bool phpser_keyed_verify(const zend_string *key, const char *buf, size_t buf_len,
                                const char *context, size_t context_len) {
    unsigned char tag[PHPSER_HMAC_TAG_LEN];
    /* An exactly tag-sized buffer authenticates an empty frame, which then
     * fails to decode; only a buffer too short to hold a tag is a tag error. */
    if (buf_len < PHPSER_HMAC_TAG_LEN) {
        return false;
    }
    size_t frame_len = buf_len - PHPSER_HMAC_TAG_LEN;
    if (phpser_keyed_tag(key, context, context_len, buf, frame_len, tag) != 0) {
        return false;
    }
    bool ok = phpser_ct_eq(tag, (const unsigned char *) buf + frame_len, PHPSER_HMAC_TAG_LEN);
    ZEND_SECURE_ZERO(tag, sizeof(tag));
    return ok;
}

phpser_keyed_rc phpser_keyed_decode(const char *buf, size_t buf_len, const zend_string *key,
                                    const char *context, size_t context_len, zval *out,
                                    int allowed_mode, HashTable *allowed_set) {
    if (key) {
        if (!phpser_keyed_verify(key, buf, buf_len, context, context_len)) {
            ZVAL_NULL(out);
            return PHPSER_KEYED_BAD_TAG;
        }
        buf_len -= PHPSER_HMAC_TAG_LEN;
    }
    if (phpser_decode_buf_opts(buf, buf_len, out, allowed_mode, allowed_set,
                               /* require_exact */ true) < 0) {
        return PHPSER_KEYED_DECODE;
    }
    return PHPSER_KEYED_OK;
}

const char *phpser_key_signing_status(void) {
    zend_string *key;
    switch (phpser_key_lookup(&key)) {
    case PHPSER_KEY_OK:
        return "enabled";
    case PHPSER_KEY_SHORT:
        return "invalid (phpser.key shorter than 32 bytes; keyed values rejected)";
    default:
        return "disabled";
    }
}

/* C API checks report through E_WARNING because callers such as APCu turn a
 * failure into a cache miss and have no exception contract of their own. */
static bool phpser_api_context_ok(const char *context, size_t context_len) {
    if (UNEXPECTED(!context || !phpser_keyed_context_valid(context, context_len))) {
        php_error_docref(NULL, E_WARNING,
            "phpser: serializer context must be 1 to %d bytes, without NUL bytes, "
            "and not \"session\"", PHPSER_KEYED_CONTEXT_MAX);
        return false;
    }
    return true;
}

static phpser_key_status phpser_api_key(zend_string **key) {
    phpser_key_status st = phpser_key_lookup(key);
    if (UNEXPECTED(st == PHPSER_KEY_SHORT)) {
        php_error_docref(NULL, E_WARNING,
            "phpser.key must be at least %d bytes; keyed value rejected", PHPSER_KEY_MIN);
    }
    return st;
}

PHP_PHPSER_API int phpser_api_serialize(const zval *value,
                                        const char *context, size_t context_len,
                                        zend_string **out) {
    zend_string *key;
    *out = NULL;
    if (!phpser_api_context_ok(context, context_len)
        || phpser_api_key(&key) == PHPSER_KEY_SHORT) {
        return PHPSER_API_ERR;
    }
    /* The encoder does not write the top-level zval; serialization hooks may
     * still mutate the objects it reaches, as with any serializer. */
    zend_string *s = phpser_keyed_encode((zval *) value, key, context, context_len);
    if (UNEXPECTED(!s)) {
        if (!EG(exception)) {
            php_error_docref(NULL, E_WARNING,
                "phpser: SHA256 hash ops unavailable; value not serialized");
        }
        return PHPSER_API_ERR;
    }
    *out = s;
    return PHPSER_API_OK;
}

PHP_PHPSER_API int phpser_api_unserialize(const char *buf, size_t buf_len,
                                          const char *context, size_t context_len,
                                          zval *out) {
    zend_string *key;
    ZVAL_NULL(out);
    if (!phpser_api_context_ok(context, context_len)
        || phpser_api_key(&key) == PHPSER_KEY_SHORT) {
        return PHPSER_API_ERR;
    }
    /* Same trust model as the unsigned session handler: the store framing is
     * authoritative, so trailing bytes are corruption, and every class may
     * instantiate. Signing is what makes an untrusted store safe. */
    switch (phpser_keyed_decode(buf, buf_len, key, context, context_len, out,
                                ALLOWED_ALL, NULL)) {
    case PHPSER_KEYED_OK:
        return PHPSER_API_OK;
    case PHPSER_KEYED_BAD_TAG:
        php_error_docref(NULL, E_WARNING,
            "phpser: %.*s value failed HMAC verification, not decoded",
            (int) context_len, context);
        return PHPSER_API_ERR_TAG;
    default:
        return PHPSER_API_ERR;
    }
}

PHP_PHPSER_API unsigned int phpser_api_version(void) {
    return PHPSER_API_VERSION;
}

/* -------------------------------------------------------------------------
 * APCu serializer. Self-contained copy of APCu's serializer ABI "0"
 * (apc_serializer.h, APCu 5.1): APCu registers a persistent constant whose
 * long value is the address of its registration function. Defining the
 * types here keeps the build free of an APCu header dependency, so a binary
 * built without APCu still registers when APCu is present at runtime.
 * ------------------------------------------------------------------------- */

#define PHPSER_APC_SERIALIZER_CONSTANT "\0apc_register_serializer-0"

typedef int (*phpser_apc_serialize_t)(unsigned char **buf, size_t *buf_len,
                                      const zval *value, void *config);
typedef int (*phpser_apc_unserialize_t)(zval *value, unsigned char *buf,
                                        size_t buf_len, void *config);
typedef int (*phpser_apc_register_t)(const char *name, phpser_apc_serialize_t serialize,
                                     phpser_apc_unserialize_t unserialize, void *config);

static bool phpser_apcu_registered = false;
/* APCu binds its cache serializer by name in its own MINIT, when only its
 * built-in "php" serializer exists, so a startup apc.serializer of "php"
 * (also the default) is final. Any other name is bound at each process's
 * first request with APCu enabled (a non-root FPM master running
 * opcache.preload binds before forking, and workers inherit it); phpser does
 * not predict that and reports only what it observes. */
static bool phpser_apcu_bound_php_at_startup = false;
/* Set the first time APCu calls either phpser callback, and inherited across
 * fork. Atomic because ZTS requests store concurrently while MINFO reads; the
 * load-first check keeps the hot path free of shared-line writes. */
static zend_atomic_bool phpser_apcu_used;

static zend_always_inline void phpser_apcu_mark_used(void) {
    if (UNEXPECTED(!zend_atomic_bool_load_ex(&phpser_apcu_used))) {
        zend_atomic_bool_store_ex(&phpser_apcu_used, true);
    }
}

static zend_string *phpser_apcu_serializer_ini(void) {
    zend_ini_entry *entry = zend_hash_str_find_ptr(EG(ini_directives),
                                                   "apc.serializer", sizeof("apc.serializer") - 1);
    return entry ? entry->value : NULL;
}

/* APCu returns 1 on success and 0 on failure, and efree()s *buf. */
static int phpser_apc_serialize(unsigned char **buf, size_t *buf_len,
                                const zval *value, void *config) {
    zend_string *s;
    (void) config;
    phpser_apcu_mark_used();
    if (phpser_api_serialize(value, PHPSER_APCU_CONTEXT,
                             sizeof(PHPSER_APCU_CONTEXT) - 1, &s) != PHPSER_API_OK) {
        return 0;
    }
    *buf = emalloc(ZSTR_LEN(s) + 1);
    memcpy(*buf, ZSTR_VAL(s), ZSTR_LEN(s) + 1);
    *buf_len = ZSTR_LEN(s);
    zend_string_release_ex(s, 0);
    return 1;
}

/* A failure makes apcu_fetch() report a miss. */
static int phpser_apc_unserialize(zval *value, unsigned char *buf, size_t buf_len,
                                  void *config) {
    (void) config;
    phpser_apcu_mark_used();
    return phpser_api_unserialize((const char *) buf, buf_len, PHPSER_APCU_CONTEXT,
                                  sizeof(PHPSER_APCU_CONTEXT) - 1, value) == PHPSER_API_OK;
}

void phpser_apcu_minit(bool persistent) {
    phpser_apcu_registered = false;
    phpser_apcu_bound_php_at_startup = false;
    zend_atomic_bool_store_ex(&phpser_apcu_used, false);
    /* APCu keeps registered serializers for the life of the process and has
     * no unregister call, so a dl() load, unmapped at request end, would
     * leave it calling unmapped code. */
    if (!persistent) {
        return;
    }
    zend_string *name = zend_string_init(PHPSER_APC_SERIALIZER_CONSTANT,
                                         sizeof(PHPSER_APC_SERIALIZER_CONSTANT) - 1, 1);
    zval *hook = zend_get_constant(name);
    zend_string_release_ex(name, 1);
    if (!hook || Z_TYPE_P(hook) != IS_LONG || Z_LVAL_P(hook) == 0) {
        return;
    }
    phpser_apc_register_t reg = (phpser_apc_register_t) (uintptr_t) Z_LVAL_P(hook);
    phpser_apcu_registered = reg(PHP_PHPSER_EXTNAME, phpser_apc_serialize,
                                 phpser_apc_unserialize, NULL) != 0;
    zend_string *startup = phpser_apcu_serializer_ini();
    phpser_apcu_bound_php_at_startup = startup && zend_string_equals_literal(startup, "php");
}

const char *phpser_apcu_status(void) {
    if (!phpser_apcu_registered) {
        return "not registered (APCu not loaded at startup, disabled by apc.enabled or "
               "apc.enable_cli, its serializer table full, or phpser loaded with dl())";
    }
    if (phpser_apcu_bound_php_at_startup) {
        return "inactive (APCu bound the php serializer at startup; "
               "set apc.serializer=phpser in php.ini)";
    }
    if (zend_atomic_bool_load_ex(&phpser_apcu_used)) {
        return "registered, used by APCu in this process or the parent it forked from";
    }
    return "registered, not yet used by APCu in this process";
}
