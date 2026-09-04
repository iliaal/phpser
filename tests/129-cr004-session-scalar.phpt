--TEST--
phpser: CR-004 session scalar root decodes to FAILURE, not empty SUCCESS
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
--FILE--
<?php
session_save_path(sys_get_temp_dir());
session_start();

// A payload decoding to a non-array root must FAIL loudly (engine logs
// "Failed to decode session object") instead of coercing to [] with SUCCESS.
$scalar = phpser_serialize(42);
$_SESSION = ["stale" => 1];
$r = session_decode($scalar);
echo "ret: ", var_export($r, true), "\n";
echo "session: ", ($_SESSION === []) ? "empty" : "nonempty", "\n";

// A genuine array payload still decodes fine after the failure.
$_SESSION = [];
session_start();
$_SESSION = ["n" => 7];
$enc = session_encode();
$_SESSION = [];
echo (session_decode($enc) !== false && ($_SESSION["n"] ?? null) === 7)
    ? "roundtrip OK\n" : "roundtrip FAIL\n";

$_SESSION = [];
session_write_close();
echo "DONE\n";
?>
--EXPECTF--
Warning: session_decode(): Failed to decode session object. Session has been destroyed in %s on line %d
ret: false
session: empty
roundtrip OK
DONE
