--TEST--
phpser: CR-003 same corrupt bytes across unsigned/signed/session entries
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

// One corrupt input, three entry points, three distinct contracts:
// unsigned -> silent NULL, signed -> throw, session -> FAILURE (false).
$corrupt = "\x01\x00\x99"; // empty dict + unknown tag

$u = phpser_unserialize($corrupt);
echo ($u === null) ? "unsigned_null OK\n" : "unsigned_null FAIL\n";

$tag = hash_hmac("sha256", $corrupt, "k", true);
try {
    phpser_unserialize_signed($corrupt . $tag, "k");
    echo "signed FAIL (no throw)\n";
} catch (Exception $e) {
    echo "signed_throw OK\n";
}

$_SESSION = ["stale" => 1];
$r = session_decode($corrupt);
echo ($r === false && $_SESSION === []) ? "session_failure OK\n" : "session_failure FAIL\n";

$_SESSION = [];
session_write_close();
echo "DONE\n";
?>
--EXPECTF--
unsigned_null OK
signed_throw OK

Warning: session_decode(): Failed to decode session object. Session has been destroyed in %s on line %d
session_failure OK
DONE
