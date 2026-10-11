--TEST--
phpser: A/B plain benchmarks clamp invalid iterations to one
--EXTENSIONS--
phpser
--FILE--
<?php
putenv('BENCH_ITERS=invalid');
putenv('BENCH_REPS=1');
putenv('BENCH_MODE=plain');
putenv('BENCH_APP');
ob_start();
require dirname(__DIR__) . '/ab.php';
$output = ob_get_clean();
var_dump($ITERS);
var_dump(substr_count($output, 'size=') === 9);

// Count work instead of asserting noisy elapsed times. Both encode and decode
// call med() with this configured iteration count.
$calls = 0;
med(function ($value) use (&$calls) { $calls++; }, null, $ITERS, 1);
var_dump($calls);
?>
--EXPECT--
int(1)
bool(true)
int(1)
