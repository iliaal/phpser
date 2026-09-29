--TEST--
phpser: HMAC uses the hardware SHA-256 backend when the CPU has one
--EXTENSIONS--
phpser
--SKIPIF--
<?php
// A hardware path that fails its self-test falls back to ext/hash and still
// produces correct tags, so only the reported backend exposes the fallback.
$machine = php_uname('m');
if (!(PHP_OS_FAMILY === 'Darwin' && $machine === 'arm64')) {
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
}
// clang on Linux aarch64 and GCC < 6 build without the hardware path.
ob_start();
(new ReflectionExtension('phpser'))->info();
if (str_contains(ob_get_clean(), 'ext/hash (not compiled)')) die('skip hardware SHA-256 path not compiled in');
?>
--FILE--
<?php
ob_start();
(new ReflectionExtension('phpser'))->info();
$info = ob_get_clean();
if (!preg_match('/^HMAC SHA-256 backend => (.+)$/m', $info, $m)) {
    echo "backend row missing\n";
    exit;
}
$backend = $m[1];
$want = in_array(php_uname('m'), ['x86_64', 'i686', 'i386'], true) ? 'sha-ni' : 'armv8';
// Valgrind's CPU model hides SHA from cpuid; its preload libraries show up in
// the client's own mappings whether or not run-tests set USE_ZEND_ALLOC.
$maps = @file_get_contents('/proc/self/maps');
$valgrind = $maps !== false && str_contains($maps, 'vgpreload');
if ($backend === $want || ($valgrind && $backend === 'ext/hash (cpu lacks feature)')) {
    echo "backend OK\n";
} else {
    echo "backend $backend, want $want\n";
}

$key = str_repeat("k", 32);
$s = phpser_serialize_signed(str_repeat("x", 1000), $key);
echo substr($s, -32) === hash_hmac('sha256', substr($s, 0, -32), $key, true) ? "tag OK\n" : "tag FAIL\n";
?>
--EXPECT--
backend OK
tag OK
