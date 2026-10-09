--TEST--
phpser: BENCH_WARMUP=0 disables benchmark warmup
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
$report = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
var_dump($report['meta']['warmup_iters']);

// Count calls rather than elapsed time: each operation has one preparation
// call plus the timed iterations, with no extra calls when warmup is zero.
foreach ([$WARMUP_ITERS, 3] as $warmup) {
    $encCalls = $decCalls = 0;
    $enc = function ($value) use (&$encCalls): string {
        $encCalls++;
        return serialize($value);
    };
    $dec = function ($blob) use (&$decCalls) {
        $decCalls++;
        return unserialize($blob);
    };
    bench_matrix(['scalar' => 42], ['counted' => [$enc, $dec]], 2, 2, $warmup);
    echo "encode=$encCalls decode=$decCalls\n";
}
?>
--EXPECT--
int(0)
encode=5 decode=5
encode=8 decode=8
