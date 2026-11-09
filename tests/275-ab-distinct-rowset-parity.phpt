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
// Check the encoded structure too. Content deduplication can hide shared
// timestamps, so wire equality alone does not prove separate allocations.
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

function has_unshared_timestamps(array $rows): bool {
    foreach ($rows as $row) {
        ob_start();
        debug_zval_dump($row['created_at']);
        $dump = ob_get_clean();
        // One owner in the row plus debug_zval_dump's argument. A shared heap
        // string has more owners; a reused literal is reported as interned.
        if (!preg_match('/ refcount\(2\)\s*$/D', $dump)) return false;
    }
    return true;
}
// Check both builders independently so identical allocation regressions in
// the two harnesses cannot pass by agreeing with each other. Single-character
// tags are interned by PHP; only their containing arrays are distinct.
echo "main timestamps: ";
var_dump(has_unshared_timestamps($bench['rowset_distinct_1000']));
echo "A/B timestamps: ";
var_dump(has_unshared_timestamps($distinct));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
main timestamps: bool(true)
A/B timestamps: bool(true)
