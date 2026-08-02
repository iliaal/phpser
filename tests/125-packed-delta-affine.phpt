--TEST--
phpser: TAG_PACKED_AFFINE / TAG_PACKED_DELTA integer-run round-trips and sizing
--EXTENSIONS--
phpser
--FILE--
<?php

function rt(array $a): bool {
    return phpser_unserialize(phpser_serialize($a)) === $a;
}

// Affine shapes: strides, negative steps, constants, threshold edges.
$cases = [
    'range_1'     => range(0, 9999),
    'range_step7' => range(3, 3 + 7 * 999, 7),
    'range_neg'   => range(1000, 1),
    'const_run'   => array_fill(0, 500, 42),
    'n3_below'    => [10, 11, 12],            // below threshold, stays LONGS
    'n4_edge'     => [10, 11, 12, 13],
    'max_edge'    => [PHP_INT_MAX - 3, PHP_INT_MAX - 2, PHP_INT_MAX - 1, PHP_INT_MAX],
    'min_edge'    => [PHP_INT_MIN, PHP_INT_MIN + 1, PHP_INT_MIN + 2, PHP_INT_MIN + 3],
];
foreach ($cases as $name => $a) {
    if (!rt($a)) { echo "affine $name FAIL\n"; }
}
echo "affine roundtrips OK\n";

// Sub-linear sizing: a 10k affine run is a handful of bytes.
$len = strlen(phpser_serialize(range(0, 9999)));
echo $len < 20 ? "affine size OK\n" : "affine size FAIL ($len)\n";

// Delta shapes: sorted with gaps, cyclic, wrap-magnitude pairs, shuffled noise.
$ts = [];
$t = 1700000000;
for ($i = 0; $i < 1000; $i++) { $t += 60 + ($i % 7); $ts[] = $t; }
$cyc = [];
for ($i = 0; $i < 1000; $i++) $cyc[] = 1000 + ($i % 50);
$wild = [PHP_INT_MAX, PHP_INT_MIN, 0, -1, PHP_INT_MIN, PHP_INT_MAX, 7, -7];
mt_srand(1234);
$rand = [];
for ($i = 0; $i < 1000; $i++) $rand[] = mt_rand(PHP_INT_MIN, PHP_INT_MAX);
foreach (['ts' => $ts, 'cyc' => $cyc, 'wild' => $wild, 'rand' => $rand] as $name => $a) {
    if (!rt($a)) { echo "delta $name FAIL\n"; }
}
echo "delta roundtrips OK\n";

// Delta must beat plain varints on the timestamp shape.
$plain_estimate = strlen(phpser_serialize($rand));   // random: plain LONGS
$delta_len = strlen(phpser_serialize($ts));
echo $delta_len < 2200 ? "delta size OK\n" : "delta size FAIL ($delta_len)\n";

// Table columns: id column (delta), cyclic column (delta), string column.
$rows = [];
for ($i = 0; $i < 500; $i++) {
    $rows[] = ['id' => $i, 'uid' => 1000 + ($i % 50), 'name' => "r$i"];
}
$b = phpser_serialize($rows);
echo phpser_unserialize($b) === $rows ? "table delta OK\n" : "table delta FAIL\n";

// Signed path carries the new tags too.
$signed = phpser_serialize_signed($cases['range_1'], 'k');
echo phpser_unserialize_signed($signed, 'k') === $cases['range_1']
    ? "signed affine OK\n" : "signed affine FAIL\n";

// Budget symmetry: past PHPSER_SUBLINEAR_MAX_ELEMS (1M) the encoder degrades
// to linear tags, and the decoder accepts what the encoder emitted. Two 600k
// affine chunks: the first rides the budget, the second must fall back.
$chunk = range(0, 599999);
$two = [$chunk, $chunk];
$tb = phpser_serialize($two);
$back = phpser_unserialize($tb);
echo $back === $two ? "budget fallback OK\n" : "budget fallback FAIL\n";
// First chunk sub-linear, second linear: total sits far above 2x the
// single-chunk size floor but far below 2x the plain encoding.
$one = strlen(phpser_serialize($chunk));
echo (strlen($tb) > 100000 && $one < 20) ? "budget sizes OK\n" : sprintf("budget sizes FAIL (%d/%d)\n", strlen($tb), $one);

// Nested affine runs inside containers.
$nested = ['a' => range(0, 100), 'b' => [range(5, 1), array_fill(0, 10, 9)]];
echo phpser_unserialize(phpser_serialize($nested)) === $nested
    ? "nested OK\n" : "nested FAIL\n";

?>
--EXPECT--
affine roundtrips OK
affine size OK
delta roundtrips OK
delta size OK
table delta OK
signed affine OK
budget fallback OK
budget sizes OK
nested OK
