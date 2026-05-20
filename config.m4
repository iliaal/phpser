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

  dnl Detect session extension so we can register as a serialize_handler.
  dnl PHP_CHECK_HEADER_INTERNAL would be cleaner, but PECL convention is to
  dnl probe via PHP_ADD_EXTENSION_DEP + AC_CHECK_HEADER on the installed
  dnl include dir.
  AC_CHECK_HEADER([ext/session/php_session.h],
    [AC_DEFINE(HAVE_PHP_SESSION, 1, [session extension headers available])
     PHP_ADD_EXTENSION_DEP(phpser, session)],
    [])

  PHP_NEW_EXTENSION(phpser, phpser.c, $ext_shared,, -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1)
  PHP_INSTALL_HEADERS([ext/phpser], [php_phpser.h])
fi
