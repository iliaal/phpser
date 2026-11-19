--TEST--
phpser: session encode that exhausts the C stack warns and stores the failure marker
--EXTENSIONS--
phpser
session
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80300) die("skip zend.max_allowed_stack_size needs PHP 8.3+");
if (@ini_set('session.serialize_handler', 'phpser') === false) {
    die('skip phpser built without session support (HAVE_PHP_SESSION undefined)');
}
// Sanitizer builds reserve more than 128K, leaving no usable stack at all.
$probe = shell_exec(escapeshellarg(getenv('TEST_PHP_EXECUTABLE')) . ' -n -d zend.max_allowed_stack_size=128K -r "echo 1;" 2>&1');
if ($probe !== '1') die("skip zend.reserved_stack_size exceeds 128K on this build");
?>
--INI--
zend.max_allowed_stack_size=128K
session.serialize_handler=phpser
session.save_handler=files
session.use_cookies=0
session.cache_limiter=
--FILE--
<?php
session_save_path(sys_get_temp_dir());
session_start();
// The handler has no frame to throw into at request-end auto-save, so the
// stack error degrades to a warning like the depth cap does.
$a = 42;
for ($i = 0; $i < 511; $i++) { $a = [$a]; }
$_SESSION = ['deep' => $a];
var_dump(session_encode());
$_SESSION = [];
session_write_close();
echo "DONE\n";
?>
--EXPECTF--
Warning: session_encode(): phpser: $_SESSION not serialized — nesting exhausted the C stack in %s on line %d
string(29) "phpser:session-not-serialized"
DONE
