--TEST--
phpser: nonnumeric BENCH_ITERS uses at least one timed iteration
--EXTENSIONS--
phpser
--FILE--
<?php
putenv('BENCH_ITERS=invalid');
putenv('BENCH_REPS=1');
putenv('BENCH_WARMUP=0');
$argv = [dirname(__DIR__) . '/bench.php', '--format=json'];
ob_start();
require $argv[0];
$report = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
var_dump($report['meta']['iters']);

// Count timed calls rather than depending on clock resolution.
$calls = 0;
time_op_sample(function ($value) use (&$calls) { $calls++; }, 42, $ITERS);
var_dump($calls);
?>
--EXPECT--
int(1)
int(1)
