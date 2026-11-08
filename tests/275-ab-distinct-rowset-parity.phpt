--TEST--
phpser: A/B workloads match the main benchmark, including distinct-allocation rowsets
--EXTENSIONS--
phpser
--FILE--
<?php
putenv('BENCH_ITERS=1');
putenv('BENCH_REPS=1');
putenv('BENCH_WARMUP=0');
putenv('BENCH_MODE=plain');
putenv('BENCH_APP');

function load_shapes(string $script, string $namespace): array {
    $argv = [$script, '--format=json'];
    $source = file_get_contents(dirname(__DIR__) . '/' . $script);
    $source = str_replace('declare(strict_types=1);', '', substr($source, 5));
    // Separate namespaces keep the harnesses' fixture class/function names
    // independent. Run their real setup and discard only their timing output.
    ob_start();
    eval('namespace ' . $namespace . '; use \Closure; use \ReflectionReference; use \RuntimeException;' . $source);
    ob_end_clean();
    return $script === 'ab.php' ? $shapes : $timed;
}

$bench = load_shapes('bench.php', 'MainFixtureParity');
$ab = load_shapes('ab.php', 'AbFixtureParity');
// Use the main harness as the source of truth, not a third copied shape list.
var_dump(array_keys($ab) === array_keys($bench));
$distinct = $ab['rowset_distinct_1000'] ?? null;
var_dump(serialize($distinct) === serialize($bench['rowset_distinct_1000']));
// Equal values are not enough: literal and separately allocated strings use
// different encode paths. Their wire representations must match the source
// fixture too, and differ from the ordinary rowset fixture.
var_dump(phpser_serialize($distinct) === phpser_serialize($bench['rowset_distinct_1000']));
var_dump(phpser_serialize($distinct) !== phpser_serialize($ab['rowset_1000']));
$noReferences = is_array($distinct);
foreach ($distinct ?? [] as $i => $row) {
    $noReferences = $noReferences && ReflectionReference::fromArrayElement($distinct, $i) === null;
    foreach ($row as $key => $value) {
        $noReferences = $noReferences && ReflectionReference::fromArrayElement($row, $key) === null;
    }
}
var_dump($noReferences);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
