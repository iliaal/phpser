--TEST--
phpser: the extension loads and the keyed path works without APCu; phpinfo() reports the serializer as not registered
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (!function_exists('shell_exec')) die('skip shell_exec disabled');
// -n drops php.ini and conf.d; APCu loaded through TEST_PHP_ARGS would survive it.
$cmd = escapeshellarg(getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY) . ' -n ' . (getenv('TEST_PHP_EXTRA_ARGS') ?: '')
    . ' -r ' . escapeshellarg('echo extension_loaded("phpser") ? (extension_loaded("apcu") ? "apcu" : "ok") : "noext";');
$probe = trim((string) shell_exec($cmd));
if ($probe !== 'ok') die("skip cannot start a child without APCu ($probe)");
?>
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpser-223-' . md5(__DIR__);
@mkdir($dir);
$script = "$dir/child.php";
file_put_contents($script, '<?php
var_dump(extension_loaded("apcu"));
ob_start();
(new ReflectionExtension("phpser"))->info();
$info = ob_get_clean();
preg_match("/APCu serializer => (.*)/", $info, $m);
echo $m[1] ?? "missing", "\n";
$s = phpser_serialize_keyed(["k" => 1], "memcached");
var_dump(phpser_unserialize_keyed($s, "memcached"));');
$cmd = escapeshellarg(getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY) . ' -n ' . (getenv('TEST_PHP_EXTRA_ARGS') ?: '')
    . ' -d ' . escapeshellarg('phpser.key=' . str_repeat('z', 40)) . ' ' . escapeshellarg($script) . ' 2>&1';
echo shell_exec($cmd);
echo "DONE\n";
?>
--CLEAN--
<?php
$dir = sys_get_temp_dir() . '/phpser-223-' . md5(__DIR__);
foreach (glob("$dir/*") as $f) @unlink($f);
@rmdir($dir);
?>
--EXPECT--
bool(false)
not registered (APCu not loaded, or disabled for this SAPI)
array(1) {
  ["k"]=>
  int(1)
}
DONE
