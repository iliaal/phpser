--TEST--
phpser: a forked GC benchmark sample exiting early affects only its serializer cell
--EXTENSIONS--
phpser
pcntl
--FILE--
<?php
putenv('BENCH_ITERS=1');
putenv('BENCH_REPS=1');
putenv('BENCH_WARMUP=0');
$argv = [dirname(__DIR__) . '/bench.php', '--format=json'];
ob_start();
require $argv[0];
ob_end_clean();

$parent = getmypid();
foreach (['warmup', 'gc_on', 'gc_off'] as $stage) {
    $calls = 0;
    $bad = function ($blob) use ($parent, $stage, &$calls) {
        if (getmypid() !== $parent) {
            // Warmup is calls 1-3 in each child; retained decode starts at 4.
            ++$calls;
            if (($stage === 'warmup' && $calls === 1)
                || ($stage === 'gc_on' && $calls === 4 && gc_enabled())
                || ($stage === 'gc_off' && $calls === 4 && !gc_enabled())) {
                exit(7);
            }
        }
        return unserialize($blob);
    };
    $results = bench_gc_retained(
        ['first' => [42], 'second' => [43]],
        ['bad' => ['serialize', $bad], 'good' => ['serialize', 'unserialize']],
        10, 1048576, 2);
    echo $stage, "\n";
    foreach ($results as $row) {
        var_dump($row['bad'] === ['err' => 'forked sample exited without a result']);
        var_dump(!isset($row['good']['err']) && $row['good']['isolated']
            && $row['good']['keep'] === 10
            && is_float($row['good']['gc_on']) && is_float($row['good']['gc_off']));
    }
}
?>
--EXPECT--
warmup
bool(true)
bool(true)
bool(true)
bool(true)
gc_on
bool(true)
bool(true)
bool(true)
bool(true)
gc_off
bool(true)
bool(true)
bool(true)
bool(true)
