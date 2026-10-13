--TEST--
phpser: a phpser.session_key under 32 bytes fails closed instead of signing with a weak key or falling back to unsigned
--EXTENSIONS--
phpser
session
--SKIPIF--
<?php
if (@ini_set('session.serialize_handler', 'phpser') === false) {
    die('skip phpser built without session support (HAVE_PHP_SESSION undefined)');
}
?>
--INI--
session.serialize_handler=phpser
session.save_handler=files
session.use_cookies=0
session.cache_limiter=
phpser.session_key=On
--FILE--
<?php
session_save_path(sys_get_temp_dir());
var_dump(ini_get('phpser.session_key'));
session_id('phpsershortkey217');
// Even a brand-new (empty) session is refused: nothing runs signed with a
// guessable key and nothing falls back to unsigned.
var_dump(session_start());
var_dump(session_status() === PHP_SESSION_ACTIVE);
var_dump(file_exists(sys_get_temp_dir() . '/sess_phpsershortkey217')
    && filesize(sys_get_temp_dir() . '/sess_phpsershortkey217') > 0);
echo "DONE\n";
?>
--CLEAN--
<?php
@unlink(sys_get_temp_dir() . '/sess_phpsershortkey217');
?>
--EXPECTF--
string(1) "1"

Warning: session_start(): phpser.session_key must be at least 32 bytes; session data rejected in %s on line %d

Warning: session_start(): Failed to decode session object. Session has been destroyed in %s on line %d
bool(false)
bool(false)
bool(false)
DONE
