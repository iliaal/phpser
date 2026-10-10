--TEST--
phpser: warmup and timed errors affect only the failing benchmark cell
--EXTENSIONS--
phpser
--FILE--
<?php
putenv('BENCH_ITERS=1');
putenv('BENCH_REPS=1');
putenv('BENCH_WARMUP=0');
$argv = [dirname(__DIR__) . '/bench.php', '--format=json'];
ob_start();
require $argv[0];
ob_end_clean();

foreach (['warmup encode', 'warmup decode', 'timed encode', 'timed decode', 'late encode', 'late decode'] as $stage) {
    $calls = ['encode' => 0, 'decode' => 0];
    $healthy = ['encode' => 0, 'decode' => 0];
    // Preparation is call 1, one warmup is call 2, timed samples start at 3.
    $failAt = str_starts_with($stage, 'warmup') ? 2 : (str_starts_with($stage, 'late') ? 4 : 3);
    $failing = [];
    $working = [];
    foreach (['encode', 'decode'] as $op) {
        $failing[] = function ($value) use (&$calls, $op, $stage, $failAt) {
            if (++$calls[$op] === $failAt && str_ends_with($stage, $op)) {
                throw new RuntimeException($stage);
            }
            return $op === 'encode' ? serialize($value) : unserialize($value);
        };
        $working[] = function ($value) use (&$healthy, $op) {
            $healthy[$op]++;
            return $op === 'encode' ? serialize($value) : unserialize($value);
        };
    }
    $results = bench_matrix(['value' => 42], ['bad' => $failing, 'good' => $working], 1, 3, 1);
    echo $stage, ': ', json_encode($results['value']['bad']), "\n";
    // No operation for the failed cell runs after its first exception.
    echo json_encode($calls), "\n";
    // A healthy cell completes preparation, warmup, and all three samples.
    var_dump($healthy === ['encode' => 5, 'decode' => 5]);
    var_dump(array_keys($results['value']['good']) === ['size', 'enc', 'dec', 'enc_iqr', 'dec_iqr']);
}
?>
--EXPECT--
warmup encode: {"err":"warmup encode"}
{"encode":2,"decode":1}
bool(true)
bool(true)
warmup decode: {"err":"warmup decode"}
{"encode":2,"decode":2}
bool(true)
bool(true)
timed encode: {"err":"timed encode"}
{"encode":3,"decode":2}
bool(true)
bool(true)
timed decode: {"err":"timed decode"}
{"encode":3,"decode":3}
bool(true)
bool(true)
late encode: {"err":"late encode"}
{"encode":4,"decode":3}
bool(true)
bool(true)
late decode: {"err":"late decode"}
{"encode":4,"decode":4}
bool(true)
bool(true)
