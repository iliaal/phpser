--TEST--
phpser: CR-005 session decode enforces exact consumption (no suffix)
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
$_SESSION = ["count" => 42, "user" => "alice"];
$enc = session_encode();

// Control: the clean encoding round-trips.
$_SESSION = [];
echo (session_decode($enc) !== false && ($_SESSION["count"] ?? null) === 42)
    ? "clean OK\n" : "clean FAIL\n";

// A suffixed store payload is corruption, not data: the store framing is
// authoritative, so the session path requires exact consumption.
$_SESSION = [];
echo (session_decode($enc . "\x00") === false)
    ? "suffix_reject OK\n" : "suffix_reject FAIL\n";

$_SESSION = [];
session_write_close();
echo "DONE\n";
?>
--EXPECTF--
clean OK

Warning: session_decode(): Failed to decode session object. Session has been destroyed in %s on line %d
suffix_reject OK
DONE
