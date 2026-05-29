--TEST--
phpser: session serialize_handler — fresh session, round-trip, empty decode, over-depth
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

// Fresh session must start without "Failed to decode / Session destroyed".
// --EXPECT-- is exact, so any stray warning here fails the test.
session_start();

$_SESSION['user'] = ['id' => 7, 'name' => 'alice', 'roles' => ['a', 'b', 'c']];
$_SESSION['count'] = 42;

// Encode goes through the phpser serializer hook.
$enc = session_encode();
echo "encoded: ", ($enc !== false && strlen($enc) > 0) ? "yes" : "no", "\n";

// Round-trip through the decode hook.
$_SESSION = [];
session_decode($enc);
echo "roundtrip: ",
    (($_SESSION['count'] ?? null) === 42 && ($_SESSION['user']['name'] ?? null) === 'alice')
    ? "OK" : "FAIL", "\n";

// Decoding an empty payload (a brand-new session) must succeed as an empty
// session, not fail and destroy it.
$_SESSION = ['stale' => 1];
$r = session_decode('');
echo "empty_decode: ", ($r !== false && $_SESSION === []) ? "OK" : "FAIL", "\n";

// Over-depth encode degrades to false with a warning, no fatal.
$deep = 'leaf';
for ($i = 0; $i < 1000; $i++) $deep = [$deep];
$_SESSION = ['deep' => $deep];
$enc2 = @session_encode();
echo "overdepth_encode_false: ", ($enc2 === false) ? "yes" : "no", "\n";

// Reset before shutdown so the request-shutdown auto-save doesn't re-encode
// the over-deep value (which would emit the degrade warning again).
$_SESSION = [];
session_write_close();

echo "DONE\n";
?>
--EXPECT--
encoded: yes
roundtrip: OK
empty_decode: OK
overdepth_encode_false: yes
DONE
