dnl phpser PHP extension build config
dnl
dnl Usage (after `phpize` against the target PHP install):
dnl   ./configure --enable-phpser
dnl   make
dnl   make install

PHP_ARG_ENABLE([phpser],
  [whether to enable phpser support],
  [AS_HELP_STRING([--enable-phpser], [Enable phpser])],
  [no])

if test "$PHP_PHPSER" != "no"; then
  AC_DEFINE(HAVE_PHPSER, 1, [phpser support enabled])

  dnl HAVE_PHP_SESSION, which gates the serialize_handler glue in
  dnl phpser_session.c / phpser_module.c, belongs to php-src's own ext/session
  dnl (defined by both its config.m4 and its config.w32). php_config.h therefore already carries it
  dnl whenever this PHP includes the session extension, and phpser must not
  dnl define it. An AC_CHECK_HEADER for ext/session/php_session.h cannot see
  dnl PHP's include directory this early in configure and always answers "no".
  dnl
  dnl The third argument marks the dependency optional. On Unix this macro is
  dnl only a configure-time consistency check, rejecting a static phpser built
  dnl against a shared session, so declaring it unconditionally is correct and
  dnl inert when session is absent.
  PHP_ADD_EXTENSION_DEP(phpser, session, true)

  PHP_NEW_EXTENSION(phpser, phpser.c phpser_hmac.c phpser_session.c phpser_module.c, $ext_shared,, -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1)
  PHP_INSTALL_HEADERS([ext/phpser], [php_phpser.h])
fi
