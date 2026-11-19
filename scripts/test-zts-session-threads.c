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

#include <dlfcn.h>
#include <link.h>
#include <pthread.h>
#include <stdatomic.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sapi/embed/php_embed.h>

#ifndef ZTS
#error This regression requires a ZTS PHP embed library.
#endif

#define NTHREADS 8
#define NREQ 20

static const char *ext_path;
static const char *key;
static atomic_int requests_ok, key_before_free, key_after_free, scan_errors;
static __thread char cookie[64];

static char *drv_read_cookies(void) { return cookie; }
static void drv_send_header(sapi_header_struct *h, void *ctx) { (void) h; (void) ctx; }

static size_t tls_size;
static int find_tls_size(struct dl_phdr_info *info, size_t sz, void *data) {
    (void) sz; (void) data;
    if (!info->dlpi_name || strcmp(info->dlpi_name, ext_path) != 0) return 0;
    for (int i = 0; i < info->dlpi_phnum; i++)
        if (info->dlpi_phdr[i].p_type == PT_TLS) tls_size = info->dlpi_phdr[i].p_memsz;
    return 1;
}

/* 1 when the calling thread's phpser TLS block holds the raw phpser.key
 * bytes, 0 when not, -1 when the block cannot be located. */
static int tls_holds_key(void) {
    void *h = dlopen(ext_path, RTLD_NOW | RTLD_NOLOAD);
    void *blk = NULL;
    int found = -1;
    if (!h) return -1;
    if (dlinfo(h, RTLD_DI_TLS_DATA, &blk) == 0 && blk && tls_size)
        found = memmem(blk, tls_size, key, strlen(key)) != NULL;
    dlclose(h);
    return found;
}

static bool run_request(const char *code) {
    bool ok = false;
    zval rv;
    SG(server_context) = (void *) 1;
    if (php_request_startup() == FAILURE) return false;
    ZVAL_UNDEF(&rv);
    zend_try {
        ok = zend_eval_string((char *) code, &rv, "session-thread request") == SUCCESS
            && Z_TYPE(rv) == IS_TRUE;
    } zend_end_try();
    zval_ptr_dtor(&rv);
    php_request_shutdown(NULL);
    return ok;
}

/* session.auto_start makes ext/session's RINIT decode the stored session
 * through phpser before phpser's own RINIT runs on this thread. */
static const char *worker_code =
    "(function () {"
    "  $ok = ($_SESSION['seed'] ?? null) === ['a' => 1, 'b' => [2.5, 'x']];"
    "  $_SESSION['n'] = ($_SESSION['n'] ?? 0) + 1;"
    "  $p = phpser_serialize_keyed(['v' => $_SESSION['n']], 'thread');"
    "  return $ok && phpser_unserialize_keyed($p, 'thread') === ['v' => $_SESSION['n']];"
    "})()";

static void *worker(void *arg) {
    int idx = (int) (intptr_t) arg;
    snprintf(cookie, sizeof(cookie), "PHPSESSID=phpserthread%02d", idx);
    (void) ts_resource(0);
    for (int i = 0; i < NREQ; i++)
        if (run_request(worker_code)) atomic_fetch_add(&requests_ok, 1);
    int before = tls_holds_key();
    ts_free_thread();
    int after = tls_holds_key();
    if (before < 0 || after < 0) atomic_fetch_add(&scan_errors, 1);
    if (before == 1) atomic_fetch_add(&key_before_free, 1);
    if (after == 1) atomic_fetch_add(&key_after_free, 1);
    return NULL;
}

int main(int argc, char **argv) {
    if (argc != 4) {
        fprintf(stderr, "usage: %s php.ini /absolute/path/phpser.so phpser.key\n", argv[0]);
        return 2;
    }
    ext_path = argv[2];
    key = argv[3];
    php_embed_module.php_ini_path_override = argv[1];
    php_embed_module.php_ini_ignore_cwd = 1;
    php_embed_module.read_cookies = drv_read_cookies;
    php_embed_module.send_header = drv_send_header;
    if (php_embed_init(argc, argv) == FAILURE) {
        fprintf(stderr, "embed startup failed\n");
        return 1;
    }
    dl_iterate_phdr(find_tls_size, NULL);
    /* php_embed_init() left a request open on the main thread, where MINIT
     * already set phpser's TSRM cache; the stored sessions are written there
     * so the worker threads have something to decode. */
    zval rv;
    ZVAL_UNDEF(&rv);
    /* php_embed_init() marks headers sent, which blocks session_start(). */
    SG(headers_sent) = 0;
    zend_try {
        zend_eval_string(
            "(function () {"
            "  session_write_close(); $n = 0;"
            "  for ($i = 0; $i < " ZEND_TOSTR(NTHREADS) "; $i++) {"
            "    session_id(sprintf('phpserthread%02d', $i));"
            "    if (!session_start()) continue;"
            "    $_SESSION['seed'] = ['a' => 1, 'b' => [2.5, 'x']];"
            "    $n += session_write_close();"
            "  }"
            "  return $n;"
            "})()", &rv, "session seed");
    } zend_end_try();
    int seeded = Z_TYPE(rv) == IS_LONG ? (int) Z_LVAL(rv) : -1;
    zval_ptr_dtor(&rv);
    pthread_t threads[NTHREADS];
    for (int i = 0; i < NTHREADS; i++)
        if (pthread_create(&threads[i], NULL, worker, (void *) (intptr_t) i) != 0) return 1;
    for (int i = 0; i < NTHREADS; i++) pthread_join(threads[i], NULL);
    php_embed_shutdown();

    int ok = atomic_load(&requests_ok);
    printf("seeded=%d/%d requests_ok=%d/%d key_in_tls_before_free=%d key_in_tls_after_free=%d\n",
        seeded, NTHREADS, ok, NTHREADS * NREQ, atomic_load(&key_before_free),
        atomic_load(&key_after_free));
    if (atomic_load(&scan_errors)) {
        fprintf(stderr, "could not locate the phpser TLS block\n");
        return 1;
    }
    /* key_before_free proves the scan sees the cached key at all. */
    if (seeded != NTHREADS || ok != NTHREADS * NREQ
        || atomic_load(&key_before_free) != NTHREADS || atomic_load(&key_after_free) != 0) {
        fprintf(stderr, "ZTS session-thread regression failed\n");
        return 1;
    }
    puts("ZTS session-thread regression passed");
    return 0;
}
