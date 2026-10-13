--TEST--
phpser: phpser_serialize_keyed()/phpser_unserialize_keyed() bind values to phpser.key and the context, and reject tampered, unsigned, and foreign input
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (!function_exists('shell_exec')) die('skip shell_exec disabled');
?>
--INI--
phpser.key=keyed-kat-key-0123456789abcdef012
--FILE--
<?php
$key = 'keyed-kat-key-0123456789abcdef012';
function attempt(callable $f): string {
    try {
        $r = $f();
        return 'accepted ' . json_encode($r);
    } catch (Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }
}

// Known answer: frame || HMAC-SHA256(frame) under
// HMAC-SHA256(phpser.key, "phpser:" . context . ":v1\0").
$v = ['id' => 7, 'tags' => ['a', 'b'], 'n' => null];
$signed = phpser_serialize_keyed($v, 'redis');
$frame = substr($signed, 0, -32);
$subkey = hash_hmac('sha256', "phpser:redis:v1\0", $key, true);
echo "kat_frame: ", $frame === phpser_serialize($v) ? "OK" : "FAIL", "\n";
echo "kat_tag: ", hash_equals(hash_hmac('sha256', $frame, $subkey, true), substr($signed, -32)) ? "OK" : "FAIL", "\n";
echo "roundtrip: ", phpser_unserialize_keyed($signed, 'redis') === $v ? "OK" : "FAIL", "\n";
echo "contexts_differ: ", phpser_serialize_keyed($v, 'memcached') !== $signed ? "OK" : "FAIL", "\n";

// Rejections, all before decoding.
echo "other_context: ", attempt(fn() => phpser_unserialize_keyed($signed, 'memcached')), "\n";
$flip = $signed; $flip[3] = chr(ord($flip[3]) ^ 1);
echo "frame_flip: ", attempt(fn() => phpser_unserialize_keyed($flip, 'redis')), "\n";
$flip = $signed; $flip[strlen($flip) - 1] = chr(ord($flip[strlen($flip) - 1]) ^ 0x80);
echo "tag_flip: ", attempt(fn() => phpser_unserialize_keyed($flip, 'redis')), "\n";
echo "truncated: ", attempt(fn() => phpser_unserialize_keyed(substr($signed, 0, -1), 'redis')), "\n";
echo "appended: ", attempt(fn() => phpser_unserialize_keyed($signed . "\0", 'redis')), "\n";
echo "tag_only: ", attempt(fn() => phpser_unserialize_keyed(substr($signed, -32), 'redis')), "\n";
echo "empty: ", attempt(fn() => phpser_unserialize_keyed('', 'redis')), "\n";
echo "unsigned: ", attempt(fn() => phpser_unserialize_keyed(phpser_serialize($v), 'redis')), "\n";
echo "raw_key_signed: ", attempt(fn() => phpser_unserialize_keyed(phpser_serialize_signed($v, $key), 'redis')), "\n";

// A validly tagged frame with trailing bytes verifies but must not decode.
$trail = phpser_serialize(1) . 'x';
$trail .= hash_hmac('sha256', $trail, $subkey, true);
echo "trailing_bytes: ", attempt(fn() => phpser_unserialize_keyed($trail, 'redis')), "\n";

// Exactly one tag's worth of bytes authenticates an empty frame, which is
// then a decode failure, not a verification failure.
echo "empty_frame: ", attempt(fn() => phpser_unserialize_keyed(hash_hmac('sha256', '', $subkey, true), 'redis')), "\n";
echo "short_31: ", attempt(fn() => phpser_unserialize_keyed(str_repeat("\0", 31), 'redis')), "\n";

// Side-effect oracle: if anything decoded, a hook or the autoloader would
// echo. The control decodes the same frame validly signed.
class Loud {
    public function __serialize(): array { return ['v' => 1]; }
    public function __unserialize(array $d): void { echo "[unserialize ran]"; }
}
class Wakey { public $w = 1; public function __wakeup(): void { echo "[wakeup ran]"; } }
class GhostTpl { public $g = 1; }
spl_autoload_register(function ($c) { echo "[autoload $c]"; });
$oframe = str_replace('GhostTpl', 'GhostZzz', phpser_serialize([new Loud, new Wakey, new GhostTpl]));
$ogood = $oframe . hash_hmac('sha256', $oframe, $subkey, true);
$otagflip = $ogood; $otagflip[strlen($otagflip) - 5] = chr(ord($otagflip[strlen($otagflip) - 5]) ^ 4);
foreach ([
    'other_context' => fn() => phpser_unserialize_keyed($ogood, 'memcached'),
    'tag_flip' => fn() => phpser_unserialize_keyed($otagflip, 'redis'),
    'appended' => fn() => phpser_unserialize_keyed($ogood . 'x', 'redis'),
    'truncated' => fn() => phpser_unserialize_keyed(substr($ogood, 0, -1), 'redis'),
    'unsigned' => fn() => phpser_unserialize_keyed($oframe, 'redis'),
    'raw_key_signed' => fn() => phpser_unserialize_keyed($oframe . hash_hmac('sha256', $oframe, $key, true), 'redis'),
] as $name => $f) {
    ob_start();
    $r = attempt($f);
    $side = ob_get_clean();
    echo "oracle_$name: ", $r, $side === '' ? '' : " SIDE EFFECTS $side", "\n";
}
ob_start();
$r = attempt(fn() => count(phpser_unserialize_keyed($ogood, 'redis')));
$side = ob_get_clean();
echo "oracle_control: ", $r, " ", implode(' ', array_map(fn($k) => str_contains($side, $k) ? "$k:seen" : "$k:MISSING",
    ['[autoload GhostZzz]', '[unserialize ran]', '[wakeup ran]'])), "\n";

echo "signed_null: ", attempt(fn() => phpser_unserialize_keyed(phpser_serialize_keyed(null, 'redis'), 'redis')), "\n";
class Secret { public $s = 1; }
$obj = phpser_unserialize_keyed(phpser_serialize_keyed(new Secret, 'redis'), 'redis', ['allowed_classes' => false]);
echo "allowed_classes: ", get_class($obj), "\n";
echo "bad_option: ", attempt(fn() => phpser_unserialize_keyed($signed, 'redis', ['allowed_classes' => 1])), "\n";

foreach (['', "a\0b", 'session', str_repeat('c', 256)] as $ctx) {
    echo "context ", json_encode($ctx === str_repeat('c', 256) ? 'c x 256' : $ctx), ": ",
        attempt(fn() => phpser_serialize_keyed(1, $ctx)), "\n";
}
echo "context_255: ", phpser_unserialize_keyed(phpser_serialize_keyed(5, str_repeat('c', 255)), str_repeat('c', 255)) === 5 ? "OK" : "FAIL", "\n";
echo "context_binary: ", phpser_unserialize_keyed(phpser_serialize_keyed(5, "\xff:v1"), "\xff:v1") === 5 ? "OK" : "FAIL", "\n";

// Separate processes: same key accepts, another key or no key does not.
$dir = sys_get_temp_dir() . '/phpser-220-' . md5(__DIR__);
@mkdir($dir);
file_put_contents("$dir/payload", $signed);
$script = "$dir/child.php";
file_put_contents($script, '<?php try { echo json_encode(phpser_unserialize_keyed(file_get_contents('
    . var_export("$dir/payload", true) . '), "redis")); } catch (Exception $e) { echo $e->getMessage(); }');
foreach (['same' => $key, 'other' => 'another-key-0123456789abcdef01234', 'none' => ''] as $label => $k) {
    $cmd = escapeshellarg(getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY) . ' ' . (getenv('TEST_PHP_EXTRA_ARGS') ?: '')
        . ' -d ' . escapeshellarg("phpser.key=$k") . ' ' . escapeshellarg($script) . ' 2>&1';
    echo "process_$label: ", trim((string) shell_exec($cmd)), "\n";
}
echo "DONE\n";
?>
--CLEAN--
<?php
$dir = sys_get_temp_dir() . '/phpser-220-' . md5(__DIR__);
foreach (glob("$dir/*") as $f) @unlink($f);
@rmdir($dir);
?>
--EXPECT--
kat_frame: OK
kat_tag: OK
roundtrip: OK
contexts_differ: OK
other_context: Exception: phpser: signature verification failed
frame_flip: Exception: phpser: signature verification failed
tag_flip: Exception: phpser: signature verification failed
truncated: Exception: phpser: signature verification failed
appended: Exception: phpser: signature verification failed
tag_only: Exception: phpser: signature verification failed
empty: Exception: phpser: signature verification failed
unsigned: Exception: phpser: signature verification failed
raw_key_signed: Exception: phpser: signature verification failed
trailing_bytes: Exception: phpser: signed payload failed to decode
empty_frame: Exception: phpser: signed payload failed to decode
short_31: Exception: phpser: signature verification failed
oracle_other_context: Exception: phpser: signature verification failed
oracle_tag_flip: Exception: phpser: signature verification failed
oracle_appended: Exception: phpser: signature verification failed
oracle_truncated: Exception: phpser: signature verification failed
oracle_unsigned: Exception: phpser: signature verification failed
oracle_raw_key_signed: Exception: phpser: signature verification failed
oracle_control: accepted 3 [autoload GhostZzz]:seen [unserialize ran]:seen [wakeup ran]:seen
signed_null: accepted null
allowed_classes: __PHP_Incomplete_Class
bad_option: ValueError: phpser_unserialize_keyed(): Argument #3 ($options) allowed_classes option must be array or bool
context "": ValueError: phpser_serialize_keyed(): Argument #2 ($context) must be 1 to 255 bytes, without NUL bytes, and not "session"
context "a\u0000b": ValueError: phpser_serialize_keyed(): Argument #2 ($context) must be 1 to 255 bytes, without NUL bytes, and not "session"
context "session": ValueError: phpser_serialize_keyed(): Argument #2 ($context) must be 1 to 255 bytes, without NUL bytes, and not "session"
context "c x 256": ValueError: phpser_serialize_keyed(): Argument #2 ($context) must be 1 to 255 bytes, without NUL bytes, and not "session"
context_255: OK
context_binary: OK
process_same: {"id":7,"tags":["a","b"],"n":null}
process_other: phpser: signature verification failed
process_none: phpser: phpser.key is not set
DONE
