--TEST--
phpser: a string length past memory_limit in a payload outside the Zend heap ends with the fatal
--DESCRIPTION--
Since PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 a memory_limit overrun no longer
bails out of the allocation: the error is queued and the allocation succeeds
up to a hard limit that _exit()s the whole process.

A payload in APCu shared memory or a C caller's buffer is not charged to
memory_limit, so one string length it declares can pass the hard limit in a
single copy. A startup INI value is the userland stand-in: it is interned in
persistent memory and returned without a copy. Each child decodes a 3 MB
string, as a value, an array key, and a dictionary entry, under a limit 64 kB
above its current usage.
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (!function_exists('shell_exec')) die('skip shell_exec disabled');
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit requires Zend allocator');
?>
--FILE--
<?php
function varint(int $v): string {
    $out = '';
    while ($v >= 0x80) {
        $out .= chr(($v & 0x7f) | 0x80);
        $v >>= 7;
    }
    return $out . chr($v);
}
$dir = sys_get_temp_dir() . '/phpser-257-' . md5(__DIR__);
@mkdir($dir);
$n = 3 * 1024 * 1024;
$big = varint($n) . str_repeat('a', $n);
// No payload byte may be NUL or a quote inside the INI string.
$payloads = [
    'value' => "\x01\x01\x01a\x0c" . $big,
    'key' => "\x01\x01\x01a\x06\x01\x02" . $big . "\x02",
    'dict' => "\x01\x01" . $big . "\x02",
];
file_put_contents("$dir/child.php", '<?php
register_shutdown_function(function () { echo "shutdown\n"; });
$s = ini_get("user_agent");
ini_set("memory_limit", (string) (memory_get_usage(true) + 65536));
phpser_unserialize($s);
echo "no fatal\n";
');
// -n would also drop the -c file that carries the payload.
$args = preg_replace('/(^|\s)-n(?=\s|$)/', ' ', (string) getenv('TEST_PHP_EXTRA_ARGS'));
foreach ($payloads as $name => $payload) {
    file_put_contents("$dir/$name.ini", 'user_agent="' . $payload . "\"\n");
    $cmd = 'PHP_INI_SCAN_DIR= ' . escapeshellarg(getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY)
        . ' -c ' . escapeshellarg("$dir/$name.ini") . " $args -d display_errors=1 -d html_errors=0 "
        . escapeshellarg("$dir/child.php") . ' 2>&1';
    echo "$name: ", trim((string) shell_exec($cmd)), "\n";
}
?>
--CLEAN--
<?php
$dir = sys_get_temp_dir() . '/phpser-257-' . md5(__DIR__);
foreach (glob("$dir/*") as $f) @unlink($f);
@rmdir($dir);
?>
--EXPECTF--
value: Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
key: Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
dict: Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
