<?php
// bench.php — A/B vs igbinary on shapes that actually show up in cache.
// Run after: make && load both extensions, e.g.:
//   php -d extension=./modules/phpser.so -d extension=igbinary.so bench.php

declare(strict_types=1);

function mk_rowset(int $rows): array {
    $out = [];
    for ($i = 0; $i < $rows; $i++) {
        $out[] = [
            'id' => $i,
            'user_id' => 1000 + ($i % 50),
            'name' => 'row_' . $i,
            'created_at' => '2026-05-19T12:00:00Z',
            'amount' => $i * 1.07,
            'active' => ($i % 3) === 0,
            'tags' => ['a', 'b', 'c'],
        ];
    }
    return $out;
}

function mk_numeric_packed(int $n): array {
    return range(0, $n - 1);
}

function mk_deep_nested(int $depth): array {
    $cur = ['leaf' => 42];
    for ($i = 0; $i < $depth; $i++) {
        $cur = ['next' => $cur, 'i' => $i];
    }
    return $cur;
}

function bench(string $label, $data, int $iters = 1000): void {
    // size
    $ig = igbinary_serialize($data);
    $ps = phpser_serialize($data);

    // serialize timing
    $t = hrtime(true);
    for ($i = 0; $i < $iters; $i++) igbinary_serialize($data);
    $ig_ser = (hrtime(true) - $t) / $iters;

    $t = hrtime(true);
    for ($i = 0; $i < $iters; $i++) phpser_serialize($data);
    $ps_ser = (hrtime(true) - $t) / $iters;

    // unserialize timing — read-heavy, the metric that matters
    $t = hrtime(true);
    for ($i = 0; $i < $iters; $i++) igbinary_unserialize($ig);
    $ig_uns = (hrtime(true) - $t) / $iters;

    $t = hrtime(true);
    for ($i = 0; $i < $iters; $i++) phpser_unserialize($ps);
    $ps_uns = (hrtime(true) - $t) / $iters;

    printf("%-22s | size: ig=%7d ps=%7d (%+5.1f%%) | ser ns: ig=%8.0f ps=%8.0f (%+5.1f%%) | uns ns: ig=%8.0f ps=%8.0f (%+5.1f%%)\n",
        $label,
        strlen($ig), strlen($ps), (strlen($ps) - strlen($ig)) * 100.0 / strlen($ig),
        $ig_ser, $ps_ser, ($ps_ser - $ig_ser) * 100.0 / $ig_ser,
        $ig_uns, $ps_uns, ($ps_uns - $ig_uns) * 100.0 / $ig_uns);
}

// correctness first
$cases = [
    'null'        => null,
    'bool'        => true,
    'int'         => PHP_INT_MIN,
    'float'       => 3.14159,
    'string'      => "hello \x00 binary",
    'rowset_100'  => mk_rowset(100),
    'rowset_1000' => mk_rowset(1000),
    'packed_1k'   => mk_numeric_packed(1000),
    'packed_10k'  => mk_numeric_packed(10000),
    'deep_50'     => mk_deep_nested(50),
];

foreach ($cases as $k => $v) {
    $rt = phpser_unserialize(phpser_serialize($v));
    if (serialize($rt) !== serialize($v)) {
        echo "ROUND-TRIP MISMATCH: $k\n";
        var_dump($v, $rt);
        exit(1);
    }
}
echo "round-trip OK\n\n";

foreach ($cases as $k => $v) {
    if (in_array($k, ['null','bool','int','float','string'], true)) continue;
    bench($k, $v, 1000);
}
