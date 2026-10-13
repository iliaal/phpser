PHP_ARG_ENABLE([phpser],
  [whether to enable phpser support],
  [AS_HELP_STRING([--enable-phpser], [Enable phpser])],
  [no])

if test "$PHP_PHPSER" != "no"; then
  AC_DEFINE(HAVE_PHPSER, 1, [phpser support enabled])

  dnl php_config.h supplies HAVE_PHP_SESSION; header checks here miss PHP's includes.
  dnl The optional dependency checks static/shared consistency and permits no session.
  PHP_ADD_EXTENSION_DEP(phpser, session, true)

  dnl Export only get_module (ZEND_DLEXPORT) and the PHP_PHPSER_API functions.
  PHPSER_CFLAGS="-DZEND_ENABLE_STATIC_TSRMLS_CACHE=1"
  AX_CHECK_COMPILE_FLAG([-fvisibility=hidden],
    [PHPSER_CFLAGS="$PHPSER_CFLAGS -fvisibility=hidden"])

  PHP_NEW_EXTENSION(phpser, phpser.c phpser_hmac.c phpser_sha256.c phpser_session.c phpser_api.c phpser_module.c, $ext_shared,, $PHPSER_CFLAGS)
  PHP_INSTALL_HEADERS([ext/phpser], [php_phpser.h php_phpser_api.h])
fi
