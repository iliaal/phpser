--TEST--
phpser: A/B GC output preserves fractional median counts
--EXTENSIONS--
phpser
--FILE--
<?php
namespace AbGcMedianOutputTest;

function hrtime(bool $asNumber): int {
    static $i = 0;
    return [0, 20, 0, 60][$i++ % 4];
}
function gc_status(): array {
    static $i = 0;
    return ['runs' => [0, 1, 0, 2][$i++ % 4]];
}
putenv('BENCH_REPS=2');
putenv('BENCH_MODE=gc');
putenv('BENCH_GC_KEEP=10');
putenv('BENCH_APP');
// GC mode exits after printing. Check its complete output in the buffer's
// final callback, keeping the real printf and all nine benchmark shapes.
ob_start(function ($output) {
    $lines = explode("\n", trim($output));
    $valid = count($lines) === 9;
    foreach ($lines as $line) {
        $valid = $valid && preg_match('/ keep=\s*10 dec_gc=\s*4\.0 gc_runs=1\.5$/', $line);
    }
    return $valid ? "nine fractional GC medians\n" : $output;
});
$source = file_get_contents(dirname(__DIR__) . '/ab.php');
$source = str_replace('declare(strict_types=1);', '', substr($source, 5));
eval('namespace ' . __NAMESPACE__ . ';' . $source);
?>
--EXPECT--
nine fractional GC medians
