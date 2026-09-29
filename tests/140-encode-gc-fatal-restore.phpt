--TEST--
phpser: a fatal error inside an encode does not leave cycle collection off for later requests
--DESCRIPTION--
A fatal error (here memory_limit) longjmps out of an encode. Any GC state the
encoder changed without the INI layer would then stay changed for every later
request served by the same process while zend.enable_gc still reads 1. The
encoder protects its identity table with pins instead, so the collector stays
on in the failing request's shutdown functions and in every later request.
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') die('skip POSIX shell exec required');
if (!function_exists('proc_open')) die('skip proc_open() unavailable');
if (!function_exists('fsockopen')) die('skip fsockopen() unavailable');
if (getenv('USE_ZEND_ALLOC') === '0') die('skip memory_limit requires Zend allocator');
?>
--FILE--
<?php
$root = sys_get_temp_dir() . '/phpser-140-' . getmypid();
@mkdir($root);
file_put_contents("$root/router.php", <<<'PHP'
<?php
if (($_GET['op'] ?? '') === 'fail') {
    register_shutdown_function(function () {
        echo "shutdown gc=", gc_enabled() ? 'on' : 'off', "\n";
    });
    $rows = [];
    for ($i = 0; $i < 20000; $i++) $rows[] = str_repeat(chr(97 + $i % 26), 1000) . $i;
    ini_set('memory_limit', (string)(memory_get_usage() + 4 * 1024 * 1024));
    phpser_serialize($rows);
    echo "no fatal\n";
    return;
}
echo "gc=", gc_enabled() ? 'on' : 'off', " ini=", ini_get('zend.enable_gc'), "\n";
PHP);

$php = getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY;
$extra = getenv('TEST_PHP_EXTRA_ARGS') ?: '';
$log = "$root/server.log";
$cmd = "exec " . escapeshellarg($php) . " $extra -d zend.enable_gc=1 -d display_errors=1 -d html_errors=0"
     . " -S localhost:0 -t " . escapeshellarg($root) . " " . escapeshellarg("$root/router.php")
     . " > " . escapeshellarg($log) . " 2>&1";
$proc = proc_open($cmd, [], $pipes);

$bound = null;
for ($i = 0; $i < 100 && $bound === null; $i++) {
    usleep(50000);
    if (preg_match('@Development Server \(https?://(.*?:\d+)\) started@', (string)@file_get_contents($log), $m)) {
        $bound = $m[1];
    }
}
if ($bound === null) {
    echo "server did not start\n", @file_get_contents($log);
    exit(1);
}

function get(string $bound, string $op): string {
    for ($i = 0; $i < 50; $i++) {
        $fp = @fsockopen("tcp://$bound");
        if ($fp) break;
        usleep(50000);
    }
    fwrite($fp, "GET /?op=$op HTTP/1.1\r\nHost: $bound\r\nConnection: close\r\n\r\n");
    $resp = stream_get_contents($fp);
    fclose($fp);
    return trim(substr($resp, strpos($resp, "\r\n\r\n") + 4));
}

echo "before: ", get($bound, 'check'), "\n";
$fail = get($bound, 'fail');
echo preg_match('/Allowed memory size of \d+ bytes exhausted/', $fail) ? "fatal OK\n" : "fatal MISSING: $fail\n";
echo str_contains($fail, "shutdown gc=on") ? "failing request shutdown gc=on\n" : "failing request: $fail\n";
echo "after: ", get($bound, 'check'), "\n";
echo "after again: ", get($bound, 'check'), "\n";

proc_terminate($proc);
proc_close($proc);
@unlink("$root/router.php");
@unlink($log);
@rmdir($root);
?>
--EXPECT--
before: gc=on ini=1
fatal OK
failing request shutdown gc=on
after: gc=on ini=1
after again: gc=on ini=1
