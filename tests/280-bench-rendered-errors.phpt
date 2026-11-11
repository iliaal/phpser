--TEST--
phpser: text and HTML benchmark tables expose failed-cell reasons
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

function check_report(string $label, callable $render, string $errors, int $unavailable): void {
    ob_start();
    $render();
    $text = ob_get_clean();
    $parts = explode("Errors:\n", $text);
    echo $label, ': ';
    // Check the complete diagnostic block, including row/serializer identity,
    // order, and no repetition for the separate size/encode/decode tables.
    var_dump(count($parts) === 2 && $parts[1] === $errors
        && substr_count($parts[0], 'n/a') === $unavailable);
}

$meta = ['php' => 'test', 'arch' => 'test', 'iters' => 1,
    'reps' => 1, 'warmup_iters' => 0];
$ok = ['size' => 10, 'enc' => 20, 'dec' => 30];
$rows = [
    'first' => ['native' => $ok, 'phpser' => ['err' => 'round-trip mismatch']],
    'second' => ['native' => ['err' => 'empty payload'],
        'phpser' => ['err' => 'decoder threw: invalid <value> "quoted" & extra']],
];
check_report('main', fn() => render_text($rows, ['native', 'phpser'], 'native', $meta),
    "  first / phpser: round-trip mismatch\n"
    . "  second / native: empty payload\n"
    . "  second / phpser: decoder threw: invalid <value> \"quoted\" & extra\n\n", 9);

// A caller can select columns without exposing errors from hidden columns.
check_report('selected columns', fn() => render_text($rows, ['native'], 'native', $meta),
    "  second / native: empty payload\n\n", 3);

$gcRows = ['cycle' => ['native' => ['keep' => 10, 'gc_on' => 20, 'gc_off' => 30],
    'phpser' => ['err' => 'isolated sample failed (exit 7)']]];
$group = ['title' => 'Retained', 'note' => 'test', 'reference' => 'native',
    'serializers' => ['native', 'phpser'], 'reps' => 1, 'results' => $gcRows];
check_report('extended GC', fn() => render_text_extended(['gc_retained' => $group], $meta),
    "  cycle N=10 / phpser: isolated sample failed (exit 7)\n\n", 2);

// Both main and extended HTML tables use this helper. Quotes and markup in
// an exception must remain text inside the attribute, not become HTML.
foreach (['main' => [$rows, ['size' => ['SIZE', 'bytes', false]]],
          'GC' => [$gcRows, extended_html_metrics('gc_retained')]] as $label => [$data, $metrics]) {
    ob_start();
    html_metric_sections($data, ['native', 'phpser'], 'native', $metrics,
        fn($s) => htmlspecialchars((string) $s));
    $html = ob_get_clean();
    preg_match_all('/<span class="na" title="([^"]*)">n\/a<\/span>/', $html, $matches);
    $expected = $label === 'main' ? ['round-trip mismatch', 'empty payload',
        'decoder threw: invalid &lt;value&gt; &quot;quoted&quot; &amp; extra']
        : array_fill(0, 2, 'isolated sample failed (exit 7)');
    echo 'HTML ', $label, ': ';
    var_dump($matches[1] === $expected && !str_contains($html, '<value>'));
}

// Successful and absent cells must not acquire invented failure messages.
foreach (['healthy' => ['native' => $ok], 'absent' => []] as $label => $row) {
    ob_start();
    render_text(['value' => $row], ['native'], 'native', $meta);
    $text = ob_get_clean();
    echo $label, ': ';
    var_dump(!str_contains($text, 'Errors:')
        && substr_count($text, 'n/a') === ($label === 'absent' ? 3 : 0));
}
?>
--EXPECT--
main: bool(true)
selected columns: bool(true)
extended GC: bool(true)
HTML main: bool(true)
HTML GC: bool(true)
healthy: bool(true)
absent: bool(true)
