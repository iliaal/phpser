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

// SLOTS (positional wire v2), rowset (columnar wire v2), and negative-int
// (zigzag) shapes round-trip through the handler — not just the assoc and
// scalar shapes pinned above.
final class SessDto {
    public function __construct(
        public int $id,
        public string $name,
        public ?string $phone,
        public array $tags,
    ) {}
}
$_SESSION = ['dto' => new SessDto(7, 'alice', null, ['a', 'b'])];
$encS = session_encode();
$_SESSION = [];
session_decode($encS);
$dto = $_SESSION['dto'] ?? null;
echo "slots_roundtrip: ",
    ($dto instanceof SessDto && $dto->id === 7
        && $dto->name === 'alice' && $dto->phone === null && $dto->tags === ['a', 'b'])
    ? "OK" : "FAIL", "\n";

$_SESSION = ['rows' => [['id' => 1, 'v' => 'x'], ['id' => 2, 'v' => 'y']]];
$encR = session_encode();
$_SESSION = [];
session_decode($encR);
echo "rowset_roundtrip: ",
    (($_SESSION['rows'] ?? null) === [['id' => 1, 'v' => 'x'], ['id' => 2, 'v' => 'y']])
    ? "OK" : "FAIL", "\n";

$_SESSION = ['neg' => -123456789, 'min' => PHP_INT_MIN];
$encN = session_encode();
$_SESSION = [];
session_decode($encN);
echo "negative_int_roundtrip: ",
    (($_SESSION['neg'] ?? null) === -123456789 && ($_SESSION['min'] ?? null) === PHP_INT_MIN)
    ? "OK" : "FAIL", "\n";

// Decoding an empty payload (a brand-new session) must succeed as an empty
// session, not fail and destroy it.
$_SESSION = ['stale' => 1];
$r = session_decode('');
echo "empty_decode: ", ($r !== false && $_SESSION === []) ? "OK" : "FAIL", "\n";
// Over-depth encode persists the failure tombstone, not false: the handler
// returns a 29-byte marker the store keeps, so the next read fails loudly
// instead of silently resuming an empty (wiped) session.
$deep = 'leaf';
for ($i = 0; $i < 1000; $i++) $deep = [$deep];
$_SESSION = ['deep' => $deep];
$enc2 = @session_encode();
echo "overdepth_encode_tombstone: ", ($enc2 === 'phpser:session-not-serialized') ? "yes" : "no", "\n";

// Reset before shutdown so the request-shutdown auto-save doesn't re-encode
// the over-deep value (which would emit the degrade warning again).
$_SESSION = [];
session_write_close();

echo "DONE\n";
?>
--EXPECT--
encoded: yes
roundtrip: OK
slots_roundtrip: OK
rowset_roundtrip: OK
negative_int_roundtrip: OK
empty_decode: OK
overdepth_encode_tombstone: yes
DONE
