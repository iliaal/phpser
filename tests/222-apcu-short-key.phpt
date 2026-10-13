--TEST--
phpser: a phpser.key under 32 bytes makes APCu reject arrays and objects instead of storing them unsigned; 32 bytes signs
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (!extension_loaded('apcu')) die('skip apcu not loaded');
if (!function_exists('shell_exec')) die('skip shell_exec disabled');
?>
--INI--
apc.enabled=1
apc.enable_cli=1
apc.serializer=phpser
phpser.key=short-key
--FILE--
<?php
// APCu retries a failed store once, so each refusal warns twice.
var_dump(apcu_store('arr', ['a' => 1]));
var_dump(apcu_exists('arr'));
var_dump(apcu_store('obj', new stdClass));
// Scalars never reach a serializer.
var_dump(apcu_store('int', 5), apcu_fetch('int'));

// Boundary in separate processes. Each child writes and reads back through
// APCu, then checks the signed size: a string entry of frame length plus 32
// takes the same shared memory as the stored value.
$dir = sys_get_temp_dir() . '/phpser-222-' . md5(__DIR__);
@mkdir($dir);
$script = "$dir/child.php";
file_put_contents($script, '<?php
$v = ["a" => [1, 2], "o" => new ArrayObject([3])];
$stored = @apcu_store("v_x", $v);
$err = error_get_last()["message"] ?? "";
if (!$stored) { echo "store refused: $err"; return; }
apcu_store("v_s", str_repeat("x", strlen(phpser_serialize($v)) + 32));
$sizes = [];
foreach (apcu_cache_info()["cache_list"] as $e) $sizes[$e["info"]] = $e["mem_size"];
echo apcu_fetch("v_x") == $v && $sizes["v_x"] === $sizes["v_s"] ? "signed round trip" : "WRONG";');
foreach ([31, 32] as $len) {
    $cmd = escapeshellarg(getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY) . ' ' . (getenv('TEST_PHP_EXTRA_ARGS') ?: '')
        . ' -d ' . escapeshellarg('phpser.key=' . str_repeat('k', $len)) . ' ' . escapeshellarg($script) . ' 2>&1';
    echo "key_len_$len: ", trim((string) shell_exec($cmd)), "\n";
}
echo "DONE\n";
?>
--CLEAN--
<?php
$dir = sys_get_temp_dir() . '/phpser-222-' . md5(__DIR__);
foreach (glob("$dir/*") as $f) @unlink($f);
@rmdir($dir);
?>
--EXPECTF--
Warning: apcu_store(): phpser.key must be at least 32 bytes; keyed value rejected in %s on line %d

Warning: apcu_store(): phpser.key must be at least 32 bytes; keyed value rejected in %s on line %d
bool(false)
bool(false)

Warning: apcu_store(): phpser.key must be at least 32 bytes; keyed value rejected in %s on line %d

Warning: apcu_store(): phpser.key must be at least 32 bytes; keyed value rejected in %s on line %d
bool(false)
bool(true)
int(5)
key_len_31: store refused: apcu_store(): phpser.key must be at least 32 bytes; keyed value rejected
key_len_32: signed round trip
DONE
