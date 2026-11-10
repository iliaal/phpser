--TEST--
phpser: main and retained-GC benchmark summaries preserve median and IQR
--EXTENSIONS--
phpser
--FILE--
<?php
namespace BenchSummaryTest;
use Closure;

set_error_handler(function ($severity, $message) {
    throw new \RuntimeException($message);
});
putenv('BENCH_ITERS=1');
putenv('BENCH_REPS=1');
putenv('BENCH_WARMUP=0');
$argv = [dirname(__DIR__) . '/bench.php', '--format=json'];
ob_start();
require $argv[0];
ob_end_clean();

// Keep the actual aggregation code; only sample production is deterministic.
$lines = file($argv[0]);
foreach (['median', 'median_sorted', 'percentile_sorted', 'iqr_sorted',
    'serializer_order', 'case_data', 'bench_matrix', 'bench_gc_retained'] as $name) {
    $function = new \ReflectionFunction($name);
    $source = implode('', array_slice($lines, $function->getStartLine() - 1,
        $function->getEndLine() - $function->getStartLine() + 1));
    eval('namespace ' . __NAMESPACE__ . '; use \\Closure; use \\RuntimeException;' . $source);
}
$samples = [];
function time_op_sample(callable $fn, $arg, int $iters): float {
    global $samples;
    return array_shift($samples);
}
function run_isolated(callable $fn): array {
    global $samples;
    return array_shift($samples);
}

foreach ([
    'single' => [[7], 7, 0],
    'two' => [[9, 1], 5, 4],
    'odd' => [[9, 1, 4], 4, 4],
    'even' => [[9, 1, 7, 3], 5, 5],
    'fractional' => [[3.5, 0.5, 2.5, 1.5], 2, 1.5],
    'duplicates' => [[4, 0, 4, 0, 4], 4, 4],
    'descending' => [[10, 8, 6, 4, 2, 0], 5, 5],
] as $label => [$times, $median, $iqr]) {
    $samples = [];
    foreach ($times as $time) array_push($samples, $time, 2 * $time);
    $cell = bench_matrix(['value' => 42], ['native' => ['serialize', 'unserialize']],
        1, count($times), 0)['value']['native'];
    echo $label, ' matrix: ';
    var_dump($cell === ['size' => 5, 'enc' => (float) $median,
        'dec' => (float) (2 * $median), 'enc_iqr' => (float) $iqr,
        'dec_iqr' => (float) (2 * $iqr)] && $samples === []);

    foreach ($times as $sampleIndex => $time) {
        foreach ([1, 2] as $factor) {
            $samples[] = ['ns' => $time * $factor, 'runs' => $time,
                'collected' => $time + 2, 'threshold' => 10000 + $sampleIndex,
                'isolated' => $sampleIndex % 2 === 0];
        }
    }
    $cell = bench_gc_retained(['value' => 42], ['native' => ['serialize', 'unserialize']],
        10, 1048576, count($times))['value']['native'];
    echo $label, ' GC: ';
    var_dump($cell === ['keep' => 10, 'isolated' => true,
        'gc_on' => (float) $median, 'gc_on_iqr' => (float) $iqr,
        'gc_off' => (float) (2 * $median), 'gc_off_iqr' => (float) (2 * $iqr),
        'gc_on_runs' => (float) $median, 'gc_on_collected' => (float) ($median + 2),
        'threshold' => 10000] && $samples === []);
}
?>
--EXPECT--
single matrix: bool(true)
single GC: bool(true)
two matrix: bool(true)
two GC: bool(true)
odd matrix: bool(true)
odd GC: bool(true)
even matrix: bool(true)
even GC: bool(true)
fractional matrix: bool(true)
fractional GC: bool(true)
duplicates matrix: bool(true)
duplicates GC: bool(true)
descending matrix: bool(true)
descending GC: bool(true)
