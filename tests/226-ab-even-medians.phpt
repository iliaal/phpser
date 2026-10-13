--TEST--
phpser: A/B timings and GC counts average both middle samples for even repetitions
--EXTENSIONS--
phpser
--FILE--
<?php
namespace AbMedianTest;

$clock = $counts = [];
function hrtime(bool $asNumber): int|float {
    global $clock;
    return $clock ? array_shift($clock) : \hrtime($asNumber);
}
function gc_status(): array {
    global $counts;
    return $counts ? ['runs' => array_shift($counts)] : \gc_status();
}

// Native hrtime(true) returns a float on 32-bit PHP. Keep that contract
// even when this test runs on a 64-bit host.
$clock = [1e20];
var_dump(hrtime(true) === 1e20);

putenv('BENCH_ITERS=1');
putenv('BENCH_REPS=1');
putenv('BENCH_MODE=plain');
putenv('BENCH_APP');
// Load the complete harness in this namespace so clock and GC observations
// can be deterministic without changing its actual aggregation functions.
$source = file_get_contents(dirname(__DIR__) . '/ab.php');
$source = str_replace('declare(strict_types=1);', '', substr($source, 5));
ob_start();
eval('namespace ' . __NAMESPACE__ . ';' . $source);
ob_end_clean();

foreach ([1, 2, 3, 4] as $reps) {
    $times = array_slice([60, 20, 100, 40], 0, $reps);
    $makeClock = function () use ($times) {
        $out = [];
        foreach ($times as $time) array_push($out, 0, $time);
        return $out;
    };
    $clock = $makeClock();
    $calls = 0;
    $plain = med(function ($value) use (&$calls) { $calls++; }, null, 2, $reps);
    $clock = $makeClock();
    $counts = [];
    foreach (array_slice([3, 0, 5, 2], 0, $reps) as $runs) {
        array_push($counts, 10, 10 + $runs);
    }
    $retained = med_retained(\phpser_serialize(42), 2, $reps);
    echo json_encode([$reps, $plain, $retained, $calls, $clock, $counts]), "\n";
}
?>
--EXPECT--
bool(true)
[1,30,[30,3],2,[],[]]
[2,20,[20,1.5],4,[],[]]
[3,30,[30,3],6,[],[]]
[4,25,[25,2.5],8,[],[]]
