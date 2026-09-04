--TEST--
phpser: CR-006 failed session encode persists a loud tombstone, not empty
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

// Over-depth $_SESSION cannot be encoded. The handler must surface an
// undecodable tombstone marker (not NULL/empty): the engine persists empty
// for NULL, which the next request would read as a brand-new SUCCESS-empty
// session — silent data loss.
$deep = "leaf";
for ($i = 0; $i < 1000; $i++) $deep = [$deep];
$_SESSION = ["deep" => $deep];
$enc = @session_encode();
echo "marker: ", ($enc === "phpser:session-not-serialized") ? "OK" : "FAIL", "\n";
echo "len: ", strlen((string)$enc), "\n";

// The marker is not a valid frame: reading it back fails loudly instead of
// masquerading as an empty session.
$_SESSION = [];
echo (@session_decode((string)$enc) === false) ? "redecode OK\n" : "redecode FAIL\n";

$_SESSION = [];
session_write_close();
echo "DONE\n";
?>
--EXPECT--
marker: OK
len: 29
redecode OK
DONE
