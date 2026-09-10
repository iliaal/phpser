PHP_ARG_ENABLE([phpser],
  [whether to enable phpser support],
  [AS_HELP_STRING([--enable-phpser], [Enable phpser])],
  [no])

if test "$PHP_PHPSER" != "no"; then
  AC_DEFINE(HAVE_PHPSER, 1, [phpser support enabled])

  dnl php_config.h supplies HAVE_PHP_SESSION; header checks here miss PHP's includes.
  dnl The optional dependency checks static/shared consistency and permits no session.
  PHP_ADD_EXTENSION_DEP(phpser, session, true)

  PHP_NEW_EXTENSION(phpser, phpser.c phpser_hmac.c phpser_session.c phpser_module.c, $ext_shared,, -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1)
  PHP_INSTALL_HEADERS([ext/phpser], [php_phpser.h])
fi
