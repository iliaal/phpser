--TEST--
phpser: failed benchmark temporary-file creation does not run a sample
--EXTENSIONS--
phpser
pcntl
--INI--
sys_temp_dir={PWD}/286-missing-temp-dir
--FILE--
<?php
putenv('BENCH_ITERS=1');
putenv('BENCH_REPS=1');
putenv('BENCH_WARMUP=0');
$argv = [dirname(__DIR__) . '/bench.php', '--format=json'];
ob_start();
require $argv[0];
ob_end_clean();

if (file_exists(sys_get_temp_dir())) die("temporary path unexpectedly exists\n");
// tempnam can issue a notice before returning false. Preserve ordinary
// non-throwing error handling so the return-value path is exercised.
set_error_handler(fn($severity, $message) => str_contains($message, 'tempnam():'));
$marker = __DIR__ . '/286-sample-ran.tmp';
try {
    run_isolated(function () use ($marker): array {
        file_put_contents($marker, 'sample ran');
        return ['value' => 42];
    });
    echo "accepted missing sample file\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
var_dump(!file_exists($marker));
var_dump(pcntl_waitpid(-1, $status, WNOHANG) === -1);

// The caller reports the specific failure for each cell without timing it.
$rows = bench_gc_retained(['first' => [42], 'second' => [43]],
    ['native' => ['serialize', 'unserialize']], 10, 1048576, 2);
foreach ($rows as $row) {
    var_dump($row === ['native' => ['err' => 'cannot create benchmark sample file']]);
}
restore_error_handler();
?>
--CLEAN--
<?php
$marker = __DIR__ . '/286-sample-ran.tmp';
if (file_exists($marker)) unlink($marker);
?>
--EXPECT--
RuntimeException: cannot create benchmark sample file
bool(true)
bool(true)
bool(true)
bool(true)
