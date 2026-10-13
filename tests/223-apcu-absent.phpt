--TEST--
phpser: the extension loads and the keyed path works without APCu; phpinfo() reports the serializer as not registered
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (!function_exists('shell_exec')) die('skip shell_exec disabled');
// -n drops php.ini and conf.d; APCu loaded through TEST_PHP_ARGS would survive
// it. The probe runs from a file: Windows escapeshellarg() strips double quotes.
$probe = sys_get_temp_dir() . '/phpser-223-probe-' . md5(__DIR__) . '.php';
file_put_contents($probe, '<?php echo extension_loaded("phpser") ? (extension_loaded("apcu") ? "apcu" : "ok") : "noext";');
$cmd = escapeshellarg(getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY) . ' -n ' . (getenv('TEST_PHP_EXTRA_ARGS') ?: '')
    . ' ' . escapeshellarg($probe) . ' 2>&1';
$out = trim((string) shell_exec($cmd));
unlink($probe);
if ($out !== 'ok') die("skip cannot start a child without APCu ($out)");
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
not registered (APCu not loaded at startup, disabled for this SAPI, or phpser loaded with dl())
array(1) {
  ["k"]=>
  int(1)
}
DONE
