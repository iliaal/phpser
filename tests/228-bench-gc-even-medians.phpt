--TEST--
phpser: extended GC benchmarks preserve fractional median counters
--EXTENSIONS--
phpser
--FILE--
<?php
namespace BenchGcMedianTest;
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

// Evaluate the actual aggregation functions with only the isolated sample
// producer replaced. Wall-clock timings and collector thresholds cannot make
// these exact midpoint assertions flaky.
$lines = file($argv[0]);
foreach (['median', 'percentile_sorted', 'iqr', 'serializer_order', 'case_data', 'bench_gc_retained'] as $name) {
    $function = new \ReflectionFunction($name);
    $source = implode('', array_slice($lines, $function->getStartLine() - 1,
        $function->getEndLine() - $function->getStartLine() + 1));
    eval('namespace ' . __NAMESPACE__ . '; use \\Closure;' . $source);
}
$samples = [];
function run_isolated(callable $sample): array {
    global $samples;
    return array_shift($samples);
}
foreach ([1, 2, 3, 4] as $reps) {
    $samples = [];
    foreach (array_slice([3, 0, 5, 2], 0, $reps) as $runs) {
        foreach ([true, false] as $on) {
            $samples[] = ['ns' => ($runs + 1) * ($on ? 10 : 20),
                'runs' => $runs, 'collected' => $runs + 2,
                'threshold' => 10000, 'isolated' => true];
        }
    }
    $cell = bench_gc_retained(['value' => 42],
        ['native' => ['serialize', 'unserialize']], 10, 1048576, $reps)['value']['native'];
    echo json_encode([$reps, $cell['gc_on'], $cell['gc_off'],
        $cell['gc_on_runs'], $cell['gc_on_collected'], $samples]), "\n";
    // JSON, text and HTML must keep the same fractional run count.
    $results = ['value' => ['native' => $cell]];
    $metrics = ['gc_on' => ['GC ON', 22]];
    ob_start();
    \render_text_tables($results, ['native'], 'native', $metrics, 14);
    $text = ob_get_clean();
    ob_start();
    \html_metric_sections($results, ['native'], 'native', ['gc_on' => ['GC ON', 'ns/op', true]], fn($s) => htmlspecialchars((string) $s));
    $html = ob_get_clean();
    $expected = [1 => '3', 2 => '1.5', 3 => '3', 4 => '2.5'][$reps];
    var_dump(str_contains($text, ' r' . $expected), str_contains($html, ' r' . $expected));
}
?>
--EXPECT--
[1,40,80,3,5,[]]
bool(true)
bool(true)
[2,25,50,1.5,3.5,[]]
bool(true)
bool(true)
[3,40,80,3,5,[]]
bool(true)
bool(true)
[4,35,70,2.5,4.5,[]]
bool(true)
bool(true)
