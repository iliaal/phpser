--TEST--
phpser: A/B GC benchmarking rejects incorrect decoded values before timing
--SKIPIF--
<?php
if (!function_exists('proc_open')) die('skip proc_open unavailable');
?>
--FILE--
<?php
$source = file_get_contents(dirname(__DIR__) . '/ab.php');
$source = str_replace('declare(strict_types=1);', '', substr($source, 5));
$path = __DIR__ . '/263-ab-gc-roundtrip-check-child.php';
foreach (['healthy', 'null', 'altered'] as $mode) {
    $setup = <<<'PHP'
<?php
namespace AbGcRoundtripTest;
function phpser_serialize($value) { return \serialize($value); }
function phpser_unserialize($blob) {
    $value = \unserialize($blob);
    if (MODE === 'null') return null;
    if (MODE === 'altered') $value[0]['id'] = -1;
    return $value;
}
putenv('BENCH_MODE=gc');
putenv('BENCH_REPS=1');
putenv('BENCH_GC_KEEP=10');
putenv('BENCH_APP=1');
PHP;
    file_put_contents($path, $setup . "\nconst MODE = " . var_export($mode, true) . ";\n" . $source);
    $process = proc_open([PHP_BINARY, '-n', '-d', 'error_reporting=-1', $path],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    echo $mode, "\n";
    if ($mode === 'healthy') {
        var_dump($status === 0, $err === '',
            substr_count($out, 'dec_gc=') === 11,
            str_contains($out, 'wallet'));
    } else {
        var_dump($status === 1, $out === '', $err === "MISMATCH rowset_100\n");
    }
}
unlink($path);
?>
--CLEAN--
<?php
@unlink(__DIR__ . '/263-ab-gc-roundtrip-check-child.php');
?>
--EXPECT--
healthy
bool(true)
bool(true)
bool(true)
bool(true)
null
bool(true)
bool(true)
bool(true)
altered
bool(true)
bool(true)
bool(true)
