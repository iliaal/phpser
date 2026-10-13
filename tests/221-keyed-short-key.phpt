--TEST--
phpser: a phpser.key under 32 bytes fails closed for the keyed functions; 32 bytes is the minimum that signs
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (!function_exists('shell_exec')) die('skip shell_exec disabled');
?>
--INI--
phpser.key=On
--FILE--
<?php
var_dump(ini_get('phpser.key'));
foreach ([
    'serialize' => fn() => phpser_serialize_keyed([1], 'redis'),
    'unserialize' => fn() => phpser_unserialize_keyed(phpser_serialize([1]) . str_repeat("\0", 32), 'redis'),
] as $name => $f) {
    try {
        $f();
        echo "$name: ACCEPTED\n";
    } catch (Exception $e) {
        echo "$name: ", $e->getMessage(), "\n";
    }
}
ob_start();
(new ReflectionExtension('phpser'))->info();
$info = ob_get_clean();
echo "minfo: ", preg_match('/keyed HMAC signing \(phpser\.key\) => (.*)/', $info, $m) ? $m[1] : "missing", "\n";

// The boundary, in separate processes: 31 bytes is refused, 32 signs with
// the documented derivation.
$dir = sys_get_temp_dir() . '/phpser-221-' . md5(__DIR__);
@mkdir($dir);
$script = "$dir/child.php";
file_put_contents($script, '<?php
try {
    $s = phpser_serialize_keyed(["a" => 1], "redis");
    $key = ini_get("phpser.key");
    $sub = hash_hmac("sha256", "phpser:redis:v1\0", $key, true);
    echo hash_equals(hash_hmac("sha256", substr($s, 0, -32), $sub, true), substr($s, -32)) ? "signed" : "WRONG TAG";
} catch (Exception $e) {
    echo $e->getMessage();
}');
foreach ([31, 32] as $len) {
    $cmd = escapeshellarg(getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY) . ' ' . (getenv('TEST_PHP_EXTRA_ARGS') ?: '')
        . ' -d ' . escapeshellarg('phpser.key=' . str_repeat('k', $len)) . ' ' . escapeshellarg($script) . ' 2>&1';
    echo "key_len_$len: ", trim((string) shell_exec($cmd)), "\n";
}
echo "DONE\n";
?>
--CLEAN--
<?php
$dir = sys_get_temp_dir() . '/phpser-221-' . md5(__DIR__);
foreach (glob("$dir/*") as $f) @unlink($f);
@rmdir($dir);
?>
--EXPECT--
string(1) "1"
serialize: phpser: phpser.key must be at least 32 bytes
unserialize: phpser: phpser.key must be at least 32 bytes
minfo: invalid (phpser.key shorter than 32 bytes; keyed values rejected)
key_len_31: phpser: phpser.key must be at least 32 bytes
key_len_32: signed
DONE
