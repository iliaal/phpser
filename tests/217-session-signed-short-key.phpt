--TEST--
phpser: a phpser.session_key under 32 bytes fails closed instead of signing with a weak key or falling back to unsigned
--EXTENSIONS--
phpser
session
--SKIPIF--
<?php
if (!function_exists('shell_exec')) die('skip shell_exec disabled');
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
$dir = sys_get_temp_dir() . '/phpser-217-' . md5(__DIR__);
@mkdir($dir);
session_save_path($dir);
var_dump(ini_get('phpser.session_key'));
session_id('shortkey217');
// Even a brand-new (empty) session is refused: nothing runs signed with a
// guessable key and nothing falls back to unsigned.
var_dump(session_start());
var_dump(session_status() === PHP_SESSION_ACTIVE);
var_dump(file_exists("$dir/sess_shortkey217") && filesize("$dir/sess_shortkey217") > 0);

// 31 bytes fails closed on read, and session_encode() after the failed start
// yields the 29-byte tombstone rather than a frame signed with the weak key.
// 32 bytes is the minimum that signs.
$script = "$dir/child.php";
file_put_contents($script, '<?php session_save_path(' . var_export($dir, true) . '); session_id("boundary217"); var_dump(@session_start());
$_SESSION = ["a" => 1];
$enc = (string) session_encode();
$key = ini_get("phpser.session_key");
$sub = hash_hmac("sha256", "phpser:session:v1\0" . session_id(), $key, true);
$frame = substr($enc, 0, -32);
echo strlen($key) >= 32 && hash_equals(hash_hmac("sha256", $frame, $sub, true), substr($enc, -32))
    ? "signed" : strlen($enc), "\n";');
foreach ([31, 32] as $len) {
    $cmd = escapeshellarg(getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY) . ' ' . (getenv('TEST_PHP_EXTRA_ARGS') ?: '')
        . ' -d ' . escapeshellarg('phpser.session_key=' . str_repeat('k', $len))
        . ' ' . escapeshellarg($script) . ' 2>&1';
    echo "key_len_$len: ", trim((string) shell_exec($cmd)), "\n";
}
unlink($script);
ob_start();
(new ReflectionExtension('phpser'))->info();
$info = ob_get_clean();
echo "minfo: ", preg_match('/session HMAC signing.*invalid/', $info) ? "invalid" : "WRONG", "\n";
echo "DONE\n";
?>
--CLEAN--
<?php
$dir = sys_get_temp_dir() . '/phpser-217-' . md5(__DIR__);
foreach (glob("$dir/*") as $f) @unlink($f);
@rmdir($dir);
?>
--EXPECTF--
string(1) "1"

Warning: session_start(): phpser.session_key must be at least 32 bytes; session data rejected in %s on line %d

Warning: session_start(): Failed to decode session object. Session has been destroyed in %s on line %d
bool(false)
bool(false)
bool(false)
key_len_31: bool(false)

Warning: session_encode(): phpser.session_key must be at least 32 bytes; session data rejected in %s on line %d
29
key_len_32: bool(true)
signed
minfo: invalid
DONE
