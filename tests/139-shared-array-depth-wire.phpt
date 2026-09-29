--TEST--
phpser: TAG_SHARED_ARRAY keeps the encode/decode depth cap symmetric and decodes hand-built frames
--EXTENSIONS--
phpser
--FILE--
<?php

function refcount_of(array $a): int {
    ob_start();
    debug_zval_dump($a);
    preg_match('/refcount\((\d+)\)/', ob_get_clean(), $m);
    return (int)$m[1] - 2;
}

// 1. Every level of a 512-deep chain is shared (the $keep list holds a second
//    reference), so every nested level carries the prefix. The prefix adds
//    no depth on either side: the same boundary as 097's nest(512) holds.
function shared_nest(int $d, array &$keep): array {
    $a = [42];
    for ($i = 1; $i < $d; $i++) { $keep[] = $a; $a = [$a]; }
    return $a;
}
$keep = [];
$v = shared_nest(512, $keep);
$s = phpser_serialize($v);
echo substr_count($s, "\x18") >= 511 ? "chain prefixed OK\n" : "chain prefixed FAIL\n";
$rt = phpser_unserialize($s);
$probe = $rt; $depth = 0;
while (is_array($probe)) { $probe = $probe[0]; $depth++; }
echo ($probe === 42 && $depth === 512) ? "depth512 shared roundtrip OK\n" : "depth512 shared roundtrip FAIL ($depth)\n";
$keep = [];
try {
    phpser_serialize(shared_nest(513, $keep));
    echo "depth513 shared throw FAIL\n";
} catch (\Exception $e) {
    echo strpos($e->getMessage(), 'maximum nesting depth') !== false
        ? "depth513 shared throw OK\n" : "depth513 shared throw FAIL\n";
}

// 2. Hand-built frames. PACKED_MIXED[SHARED(PACKED_LONGS[1,2]), REF 0, REF 0].
$H2 = "\x02\x00";
$f = $H2 . "\x07\x03" . "\x18\x08\x02\x02\x04" . "\x10\x00" . "\x10\x00";
$d = phpser_unserialize($f);
echo ($d === [[1, 2], [1, 2], [1, 2]] && refcount_of($d[0]) === 3)
    ? "crafted shared OK\n" : "crafted shared FAIL\n";

// The version byte is a minimum-reader signal, not a gate.
$d = phpser_unserialize("\x01\x00" . substr($f, 2));
echo $d === [[1, 2], [1, 2], [1, 2]] ? "v1 header OK\n" : "v1 header FAIL\n";

// The id is claimed after the array's children: NEW_REF inside takes id 0,
// the shared array id 1.
$f = $H2 . "\x07\x03" . "\x18\x07\x01\x11\x03\x0e" . "\x10\x01" . "\x10\x00";
$d = phpser_unserialize($f);
echo ($d[0] === [7] && $d[1] === [7] && $d[2] === 7) ? "post-order id OK\n" : "post-order id FAIL\n";

// 3. A duplicate key drops the only owner of a shared array before a later
//    TAG_REF reads it; the id-table pin keeps it alive (dict: k, j).
$f = "\x02\x02\x01k\x01j" . "\x06\x03"
   . "\x01\x00" . "\x18\x08\x01\x0a"
   . "\x01\x00" . "\x03\x02"
   . "\x01\x01" . "\x10\x00";
$d = phpser_unserialize($f);
echo $d === ['k' => 1, 'j' => [5]] ? "dup key pin OK\n" : "dup key pin FAIL\n";
$d = phpser_unserialize_signed(phpser_serialize_signed($d, 'k'), 'k');
echo $d === ['k' => 1, 'j' => [5]] ? "signed OK\n" : "signed FAIL\n";

// 4. A doubling DAG: level k is [level k-1, level k-1]. 21 levels in 148
//    bytes expand to 2^20 leaves logically, but decode allocates one array
//    per level and re-encoding emits each level once.
$f = $H2 . "\x07\x15" . "\x18\x08\x01\x02";
for ($k = 1; $k <= 20; $k++) $f .= "\x18\x07\x02\x10" . chr($k - 1) . "\x10" . chr($k - 1);
$before = memory_get_usage();
$d = phpser_unserialize($f);
$grown = memory_get_usage() - $before;
// Walks one edge per level; === short-circuits on the identical table.
function dag_levels(array $v): int {
    $levels = 0;
    while (count($v) === 2 && $v[0] === $v[1]) { $v = $v[0]; $levels++; }
    return $v === [1] ? $levels : -1;
}
$levels = dag_levels($d[20]);
echo (strlen($f) === 148 && $levels === 20 && $grown < 64 * 1024
      && refcount_of($d[0]) === 3)
    ? "dag decode OK\n" : "dag decode FAIL (" . strlen($f) . "/$levels/$grown)\n";
$re = phpser_serialize($d);
echo (strlen($re) < 2 * strlen($f) && dag_levels(phpser_unserialize($re)[20]) === 20)
    ? "dag reencode OK\n" : "dag reencode FAIL (" . strlen($re) . ")\n";

// 5. Every array container tag is a legal payload, including a TAG_TABLE.
$rows = [['a' => 1, 'b' => 'x'], ['a' => 2, 'b' => 'y']];
$shared_table = ['t1' => $rows, 't2' => $rows];
$d = phpser_unserialize(phpser_serialize(['w' => $shared_table, 'z' => $rows]));
echo ($d['w']['t1'] === $rows && $d['z'] === $rows && refcount_of($d['z']) === 3)
    ? "table payload OK\n" : "table payload FAIL\n";
?>
--EXPECT--
chain prefixed OK
depth512 shared roundtrip OK
depth513 shared throw OK
crafted shared OK
v1 header OK
post-order id OK
dup key pin OK
signed OK
dag decode OK
dag reencode OK
table payload OK
