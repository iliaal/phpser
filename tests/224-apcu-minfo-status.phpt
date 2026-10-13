--TEST--
phpser: phpinfo() reports APCu serializer use as observed in the process, and a startup php serializer as inactive
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
--FILE--
<?php
function status(): string {
    ob_start();
    (new ReflectionExtension('phpser'))->info();
    return preg_match('/APCu serializer => (.*)/', ob_get_clean(), $m) ? $m[1] : 'missing';
}
echo "start: ", status(), "\n";
// Scalars never reach a serializer.
apcu_store('n', 1);
echo "after_scalar: ", status(), "\n";
apcu_store('a', [1, 2]);
echo "after_store: ", status(), "\n";

// apc.serializer=php is bound by APCu at startup, before phpser registers.
$dir = sys_get_temp_dir() . '/phpser-224-' . md5(__DIR__);
@mkdir($dir);
$script = "$dir/child.php";
file_put_contents($script, '<?php
apcu_store("a", [1, 2]);
var_dump(apcu_fetch("a"));
ob_start();
(new ReflectionExtension("phpser"))->info();
preg_match("/APCu serializer => (.*)/", ob_get_clean(), $m);
echo $m[1] ?? "missing", "\n";');
$cmd = escapeshellarg(getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY) . ' ' . (getenv('TEST_PHP_EXTRA_ARGS') ?: '')
    . ' -d apc.serializer=php ' . escapeshellarg($script) . ' 2>&1';
echo "php_child: ", trim(str_replace("\n", ' ', (string) shell_exec($cmd))), "\n";
echo "DONE\n";
?>
--CLEAN--
<?php
$dir = sys_get_temp_dir() . '/phpser-224-' . md5(__DIR__);
foreach (glob("$dir/*") as $f) @unlink($f);
@rmdir($dir);
?>
--EXPECT--
start: registered, not yet used by APCu in this process
after_scalar: registered, not yet used by APCu in this process
after_store: registered, used by APCu in this process or the parent it forked from
php_child: array(2) {   [0]=>   int(1)   [1]=>   int(2) } inactive (APCu bound the php serializer at startup; set apc.serializer=phpser in php.ini)
DONE
