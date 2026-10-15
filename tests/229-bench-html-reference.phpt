--TEST--
phpser: HTML benchmark prose names the same reference as its tables
--EXTENSIONS--
phpser
--FILE--
<?php
putenv('BENCH_ITERS=1');
putenv('BENCH_REPS=1');
putenv('BENCH_WARMUP=0');
$argv = [dirname(__DIR__) . '/bench.php', '--format=json'];
ob_start();
require $argv[0];
ob_end_clean();

$meta = ['php' => '8.4', 'arch' => 'test', 'os' => 'test', 'iters' => 1,
    'reps' => 1, 'warmup_iters' => 0, 'phpser' => 'test',
    'igbinary' => null, 'msgpack' => null, 'date' => '2026-01-01'];
foreach ([['phpser'], ['phpser', 'serialize'], ['phpser', 'igbinary'],
    ['phpser', 'igbinary', 'serialize', 'msgpack']] as $names) {
    $reference = in_array('igbinary', $names, true) ? 'igbinary' : 'phpser';
    $row = [];
    foreach ($names as $name) $row[$name] = ['size' => 10, 'enc' => 20, 'dec' => 30];
    ob_start();
    render_html(['value' => $row], $names, $reference, $meta);
    $html = ob_get_clean();
    $valid = str_contains($html, $reference . ' is the baseline;')
        && str_contains($html, "column's delta vs. " . $reference . ': green beats ' . $reference)
        && str_contains($html, $reference . ' marked <code>*</code> is the reference column.')
        && substr_count($html, '>' . $reference . ' *</th>') === 3
        && str_contains($html, '<b>igbinary</b> (the reference)') === ($reference === 'igbinary');
    echo implode(',', $names), ': ', $valid ? 'consistent' : 'WRONG REFERENCE', "\n";
}
?>
--EXPECT--
phpser: consistent
phpser,serialize: consistent
phpser,igbinary: consistent
phpser,igbinary,serialize,msgpack: consistent
