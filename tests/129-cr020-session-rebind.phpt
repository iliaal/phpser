--TEST--
phpser: CR-020 repeated session decodes rebind cleanly (no refcount drift)
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

// Each decode replaces PS(http_session_vars) with a fresh NEW_REF and
// re-binds $_SESSION through update_ind (which shares, not addrefs). Two
// sequential decodes with live payloads pin the trio: the second content
// wins, and teardown frees exactly once (a missing or doubled addref would
// corrupt or leak the reference across these cycles).
$_SESSION = ["n" => 1];
$e1 = session_encode();
$_SESSION = ["n" => 2, "extra" => [1, 2, 3]];
$e2 = session_encode();

$_SESSION = [];
session_decode($e1);
$a = $_SESSION["n"] ?? null;
session_decode($e2);
$b = $_SESSION["n"] ?? null;
$c = $_SESSION["extra"] ?? null;
echo ($a === 1 && $b === 2 && $c === [1, 2, 3]) ? "rebind OK\n" : "rebind FAIL\n";

$_SESSION = [];
session_decode($e1);
echo (($_SESSION["n"] ?? null) === 1 && !isset($_SESSION["extra"]))
    ? "rebind_back OK\n" : "rebind_back FAIL\n";

$_SESSION = [];
session_write_close();
echo "DONE\n";
?>
--EXPECT--
rebind OK
rebind_back OK
DONE
