--TEST--
phpser: HMAC uses the hardware SHA-256 backend when the CPU has one
--EXTENSIONS--
phpser
--SKIPIF--
<?php
// A hardware path that fails its self-test falls back to ext/hash and still
// produces correct tags, so only the reported backend exposes the fallback.
$machine = php_uname('m');
if (PHP_OS_FAMILY === 'Darwin' && $machine === 'arm64') return;
if (PHP_OS_FAMILY !== 'Linux') die('skip needs Linux /proc/cpuinfo or Apple arm64');
$cpu = @file_get_contents('/proc/cpuinfo');
if ($cpu === false) die('skip /proc/cpuinfo unreadable');
if (in_array($machine, ['x86_64', 'i686', 'i386'], true)) {
    if (!preg_match('/^flags\s*:.*\bsha_ni\b/m', $cpu)) die('skip CPU lacks SHA-NI');
} elseif ($machine === 'aarch64') {
    if (!preg_match('/^Features\s*:.*\bsha2\b/m', $cpu)) die('skip CPU lacks ARMv8 SHA2');
} else {
    die("skip no hardware SHA-256 path for $machine");
}
?>
--FILE--
<?php
ob_start();
(new ReflectionExtension('phpser'))->info();
$info = ob_get_clean();
if (!preg_match('/^HMAC SHA-256 backend => (\S+)$/m', $info, $m)) {
    echo "backend row missing\n";
    exit;
}
$want = in_array(php_uname('m'), ['x86_64', 'i686', 'i386'], true) ? 'sha-ni' : 'armv8';
echo $m[1] === $want ? "backend OK\n" : "backend {$m[1]}, want $want\n";

$key = str_repeat("k", 32);
$s = phpser_serialize_signed(str_repeat("x", 1000), $key);
echo substr($s, -32) === hash_hmac('sha256', substr($s, 0, -32), $key, true) ? "tag OK\n" : "tag FAIL\n";
?>
--EXPECT--
backend OK
tag OK
