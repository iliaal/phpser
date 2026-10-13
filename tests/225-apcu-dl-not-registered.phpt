--TEST--
phpser: loaded with dl(), phpser does not register with APCu, whose serializer table outlives the request
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (!function_exists('shell_exec')) die('skip shell_exec disabled');
if (PHP_OS_FAMILY === 'Windows') die('skip dl() path handling differs on Windows');
if (!extension_loaded('apcu')) die('skip apcu not loaded');
if (!is_file(ini_get('extension_dir') . '/apcu.so')) die('skip apcu.so not in extension_dir');
if (!preg_match('/extension=(\S*phpser\.so)/', (string) getenv('TEST_PHP_EXTRA_ARGS'))) die('skip phpser.so not loaded by path');
?>
--FILE--
<?php
preg_match('/extension=(\S*phpser\.so)/', getenv('TEST_PHP_EXTRA_ARGS'), $m);
$phpser = trim($m[1], "'\"");
$apcu = ini_get('extension_dir') . '/apcu.so';
$dir = sys_get_temp_dir() . '/phpser-225-' . md5(__DIR__);
@mkdir($dir);
$script = "$dir/child.php";
file_put_contents($script, '<?php
if (!extension_loaded("phpser")) var_dump(dl(' . var_export(basename($phpser), true) . '));
ob_start();
(new ReflectionExtension("apcu"))->info();
preg_match("/Serialization Support => (.*)/", ob_get_clean(), $m);
echo "apcu: ", $m[1] ?? "missing", "\n";
ob_start();
(new ReflectionExtension("phpser"))->info();
preg_match("/APCu serializer => (.*)/", ob_get_clean(), $m);
echo "phpser: ", $m[1] ?? "missing", "\n";');
$base = escapeshellarg(getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY) . ' -n -d ' . escapeshellarg("extension=$apcu")
    . ' -d apc.enable_cli=1 -d apc.serializer=phpser';
echo "== dl\n", shell_exec("$base -d enable_dl=1 -d " . escapeshellarg('extension_dir=' . dirname($phpser)) . ' ' . escapeshellarg($script) . ' 2>&1');
echo "== startup\n", shell_exec("$base -d " . escapeshellarg("extension=$phpser") . ' ' . escapeshellarg($script) . ' 2>&1');
?>
--CLEAN--
<?php
$dir = sys_get_temp_dir() . '/phpser-225-' . md5(__DIR__);
foreach (glob("$dir/*") as $f) @unlink($f);
@rmdir($dir);
?>
--EXPECTF--
== dl
bool(true)
apcu: php
phpser: not registered (APCu not loaded at startup, disabled for this SAPI, or phpser loaded with dl())
== startup
apcu: php, phpser
phpser: registered, not yet used by APCu in this process
