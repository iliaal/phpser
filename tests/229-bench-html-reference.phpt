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
$cases = [
    [['phpser'], '<b>phpser</b>'],
    [['phpser', 'serialize'], "<b>phpser</b>, PHP's native <b>serialize()</b>"],
    [['phpser', 'msgpack'], '<b>phpser</b>, <b>msgpack</b>'],
    [['phpser', 'serialize', 'msgpack'], "<b>phpser</b>, PHP's native <b>serialize()</b>, <b>msgpack</b>"],
    [['phpser', 'igbinary'], '<b>phpser</b>, <b>igbinary</b>'],
    [['phpser', 'igbinary', 'serialize'], "<b>phpser</b>, <b>igbinary</b>, PHP's native <b>serialize()</b>"],
    [['phpser', 'igbinary', 'msgpack'], '<b>phpser</b>, <b>igbinary</b>, <b>msgpack</b>'],
    [['phpser', 'igbinary', 'serialize', 'msgpack'], "<b>phpser</b>, <b>igbinary</b>, PHP's native <b>serialize()</b>, <b>msgpack</b>"],
];
foreach ($cases as [$names, $labels]) {
    $reference = in_array('igbinary', $names, true) ? 'igbinary' : 'phpser';
    $row = [];
    foreach ($names as $name) $row[$name] = ['size' => 10, 'enc' => 20, 'dec' => 30];
    ob_start();
    render_html(['value' => $row], $names, $reference, $meta);
    $html = ob_get_clean();
    preg_match('/<p class="lede">(.*?)<\/p>/s', $html, $match);
    $expected = 'A decoder-optimized binary serializer for read-heavy PHP cache workloads. '
        . "This report measures $labels across cache-shaped payloads. Lower is better on every metric. "
        . "$reference is the baseline; each percentage is that column's delta vs. $reference: "
        . "green beats $reference, red loses to it.";
    $valid = preg_replace('/\s+/', ' ', trim($match[1] ?? '')) === $expected
        && str_contains($html, $reference . ' is the baseline;')
        && str_contains($html, "column's delta vs. " . $reference . ': green beats ' . $reference)
        && str_contains($html, $reference . ' marked <code>*</code> is the reference column.')
        && substr_count($html, '>' . $reference . ' *</th>') === 3;
    echo implode(',', $names), ': ', $valid ? 'consistent' : 'WRONG REFERENCE', "\n";
}
?>
--EXPECT--
phpser: consistent
phpser,serialize: consistent
phpser,msgpack: consistent
phpser,serialize,msgpack: consistent
phpser,igbinary: consistent
phpser,igbinary,serialize: consistent
phpser,igbinary,msgpack: consistent
phpser,igbinary,serialize,msgpack: consistent
