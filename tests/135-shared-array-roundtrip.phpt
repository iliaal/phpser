--TEST--
phpser: pointer-shared nested arrays encode once (TAG_SHARED_ARRAY) and decode to one refcounted zend_array
--EXTENSIONS--
phpser
--FILE--
<?php

function refcount_of(array $a): int {
    ob_start();
    debug_zval_dump($a);
    preg_match('/refcount\((\d+)\)/', ob_get_clean(), $m);
    // The by-value argument and the debug_zval_dump() call each add one.
    return (int)$m[1] - 2;
}

// 1. Rowset shape (TAG_TABLE, PACKED_MIXED column): every row holds the same
//    literal tags array. Round-trips exactly and shares one decoded array.
$rows = [];
for ($i = 0; $i < 50; $i++) {
    $rows[] = ['id' => $i, 'name' => "row_$i", 'tags' => ['a', 'b', 'c']];
}
$s = phpser_serialize($rows);
$d = phpser_unserialize($s);
echo $d === $rows ? "rowset roundtrip OK\n" : "rowset roundtrip FAIL\n";
echo ord($s[0]) === 2 ? "rowset v2 header OK\n" : "rowset v2 header FAIL\n";
$rc = refcount_of($d[0]['tags']);
echo $rc === 50 ? "rowset shared refcount OK\n" : "rowset shared refcount FAIL ($rc)\n";

// 2. COW after decode: writing one row's tags separates only that row.
$d[3]['tags'][] = 'z';
$d[4]['tags'][0] = 'q';
$others_intact = true;
foreach ($d as $i => $row) {
    if ($i === 3 || $i === 4) continue;
    if ($row['tags'] !== ['a', 'b', 'c']) $others_intact = false;
}
echo ($others_intact && $d[3]['tags'] === ['a', 'b', 'c', 'z']
      && $d[4]['tags'] === ['q', 'b', 'c'])
    ? "cow separation OK\n" : "cow separation FAIL\n";
$rc = refcount_of($d[0]['tags']);
echo $rc === 48 ? "cow refcount OK\n" : "cow refcount FAIL ($rc)\n";

// 3. Repeats cost a back-reference, not a copy: 50 shared copies of a
//    100-string array stay within a few bytes per repeat of one copy.
$big = [];
for ($i = 0; $i < 100; $i++) $big[] = str_repeat('x', 10) . $i;
$one = strlen(phpser_serialize(['k' => [$big]]));
$many = strlen(phpser_serialize(['k' => array_fill(0, 50, $big)]));
echo ($many - $one) <= 49 * 3 ? "backref size OK\n" : "backref size FAIL ($one/$many)\n";

// 4. DTO shape (TAG_OBJECT_SLOTS): the shared literal in a typed property.
final class SharedDto {
    public function __construct(public int $id, public array $tags) {}
}
$dtos = [];
for ($i = 0; $i < 20; $i++) $dtos[] = new SharedDto($i, ['active', 'verified']);
$dd = phpser_unserialize(phpser_serialize($dtos));
echo $dd == $dtos ? "dto roundtrip OK\n" : "dto roundtrip FAIL\n";
$rc = refcount_of($dd[0]->tags);
echo $rc === 20 ? "dto shared refcount OK\n" : "dto shared refcount FAIL ($rc)\n";
$dd[1]->tags[] = 'beta';
echo ($dd[0]->tags === ['active', 'verified'] && $dd[1]->tags === ['active', 'verified', 'beta'])
    ? "dto cow OK\n" : "dto cow FAIL\n";

// 5. Runtime-built (non-literal) arrays shared through variables, under
//    packed and assoc parents.
$heap = ['x' => str_repeat('y', 3) . '1', 'n' => 7];
$mixed = ['p' => $heap, 'q' => [$heap, 5], 'r' => ['deep' => ['in' => $heap]]];
$dm = phpser_unserialize(phpser_serialize($mixed));
echo $dm === $mixed ? "assoc roundtrip OK\n" : "assoc roundtrip FAIL\n";
$rc = refcount_of($dm['p']);
echo $rc === 3 ? "assoc shared refcount OK\n" : "assoc shared refcount FAIL ($rc)\n";

// 6. Signed path carries the tag.
$sig = phpser_serialize_signed($rows, 'key');
$ds = phpser_unserialize_signed($sig, 'key');
echo ($ds === $rows && refcount_of($ds[0]['tags']) === 50)
    ? "signed OK\n" : "signed FAIL\n";

// 7. Session-shaped round trip through the top-level array.
$sess = ['cart' => [['sku' => 'a', 'opts' => $heap], ['sku' => 'b', 'opts' => $heap]]];
$dsess = phpser_unserialize(phpser_serialize($sess));
echo ($dsess === $sess && refcount_of($dsess['cart'][0]['opts']) === 2)
    ? "nested rows OK\n" : "nested rows FAIL\n";
?>
--EXPECT--
rowset roundtrip OK
rowset v2 header OK
rowset shared refcount OK
cow separation OK
cow refcount OK
backref size OK
dto roundtrip OK
dto shared refcount OK
dto cow OK
assoc roundtrip OK
assoc shared refcount OK
signed OK
nested rows OK
