--TEST--
phpser: benchmark count settings match the documented default and clamp rules
--FILE--
<?php
// Evaluate the real setup blocks without running their default-sized timing
// loops. No setting expressions are copied into the test.
function settings(string $script, string|false $value): array {
    foreach (['BENCH_ITERS', 'BENCH_REPS', 'BENCH_WARMUP'] as $name) {
        putenv($value === false ? $name : "$name=$value");
    }
    $source = file_get_contents(dirname(__DIR__) . '/' . $script);
    $start = strpos($source, '$ITERS =');
    $end = strpos($source, $script === 'bench.php'
        ? 'function median(' : 'function ab_median(');
    if ($start === false || $end === false || $end <= $start) {
        throw new RuntimeException('benchmark setup block not found');
    }
    $argv = [$script];
    eval(substr($source, $start, $end - $start));
    return $script === 'bench.php' ? [$ITERS, $REPS, $WARMUP_ITERS] : [$ITERS, $REPS];
}

foreach ([
    'unset' => false,
    'empty' => '',
    'zero' => '0',
    'negative' => '-3',
    'nonnumeric' => 'invalid',
    'positive' => '2',
    'fractional' => '2.9',
    'numeric prefix' => '12junk',
    // Only the literal string "0" selects the default, not all zero forms.
    'decimal zero' => '0.0',
    'padded zero' => '00',
] as $label => $value) {
    echo $label, ': ', json_encode([settings('bench.php', $value),
        settings('ab.php', $value)]), "\n";
}
?>
--EXPECT--
unset: [[1000,35,100],[1000,15]]
empty: [[1000,35,100],[1000,15]]
zero: [[1000,35,0],[1000,15]]
negative: [[1,1,0],[1,1]]
nonnumeric: [[1,1,0],[1,1]]
positive: [[2,2,2],[2,2]]
fractional: [[2,2,2],[2,2]]
numeric prefix: [[12,12,12],[12,12]]
decimal zero: [[1,1,0],[1,1]]
padded zero: [[1,1,0],[1,1]]
