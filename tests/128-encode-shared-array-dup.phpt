--TEST--
phpser: encode duplicates a shared nested array without reading past a compacted dup (BUG-R2-C4 hole-compaction guard)
--DESCRIPTION--
The generic array walk copies a shared (refcount>1) nested table before it
dispatches per-element hooks (enc_pin_walk). zend_array_dup preserves a packed
table's holes and indices, but COMPACTS an assoc table's UNDEF holes, so the
duplicate has fewer buckets than the source. The value walk must be bounded by
the duplicate's own nNumUsed, not the source's, or it reads uninitialized
trailing buckets. These cases do no in-place SPL write, so they run on every
lane (a debug/ASAN build catches the uninitialized read) unlike test 126.
--EXTENSIONS--
phpser
--FILE--
<?php

// A shared NESTED assoc array with UNDEF holes takes the duplicate path.
$holey = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5];
unset($holey['b'], $holey['d']);                 // nNumUsed 5, nNumOfElements 3
$hx = ['w' => $holey, 'w2' => $holey];           // $holey shared (refcount>1), depth 2
$hrt = phpser_unserialize(phpser_serialize($hx));
echo ($hrt === ['w' => ['a' => 1, 'c' => 3, 'e' => 5], 'w2' => ['a' => 1, 'c' => 3, 'e' => 5]])
    ? "shared_holey_assoc OK\n" : "shared_holey_assoc FAIL\n";

// Sparse packed with holes stays index-keyed through the duplicate path.
$sp = [10, 11, 12, 13, 14];
unset($sp[1], $sp[3]);
$spx = ['p' => $sp, 'p2' => $sp];
$sprt = phpser_unserialize(phpser_serialize($spx));
echo ($sprt === ['p' => [0 => 10, 2 => 12, 4 => 14], 'p2' => [0 => 10, 2 => 12, 4 => 14]])
    ? "shared_holey_packed OK\n" : "shared_holey_packed FAIL\n";

// A shared nested array with a hole and object values (MIXED packed column),
// walked twice, keeps object identity across the duplicate.
class Cell128 { public int $n; public function __construct(int $n) { $this->n = $n; } }
$objrow = [new Cell128(1), new Cell128(2), new Cell128(3)];
unset($objrow[1]);
$ox = ['r' => $objrow, 'r2' => $objrow];
$ort = phpser_unserialize(phpser_serialize($ox));
echo ($ort['r'][0] instanceof Cell128 && $ort['r'][0]->n === 1
      && $ort['r'][2]->n === 3 && !isset($ort['r'][1]))
    ? "shared_holey_objrow OK\n" : "shared_holey_objrow FAIL\n";

// Non-mutating ArrayObject round-trips through the duplicate path.
$plain = new ArrayObject(['a' => 1, 'b' => [1, 2, 3], 'c' => 'hi']);
$rt = phpser_unserialize(phpser_serialize($plain));
echo ($rt instanceof ArrayObject && $rt->getArrayCopy() === ['a' => 1, 'b' => [1, 2, 3], 'c' => 'hi'])
    ? "plain_roundtrip OK\n" : "plain_roundtrip FAIL\n";

// --- Debug-safe ArrayObject dup-isolation variant (runs on every lane, cf.
//     the test-126 lane matrix). No in-place SPL write: the hook mutates the
//     shared table through an ordinary PHP reference alias, which is
//     engine-legal under ZEND_DEBUG. The walk still holds a private
//     duplicate (enc_pin_walk), so the emitted frame is the pre-mutation
//     snapshot: later elements read from the dup, hook-added keys excluded.
#[\AllowDynamicProperties]
class IsoGrow {
    public static $storeRef = null;
    public function __serialize(): array {
        $s = &self::$storeRef;
        for ($i = 0; $i < 32; $i++) $s["g$i"] = 'x';
        return ['v' => 1];
    }
}
$iao = new ArrayObject(['obj' => new IsoGrow(), 'a' => 1]);
$istore = $iao->__serialize()[1];   // aliases the live storage (refcount>1)
IsoGrow::$storeRef = &$istore;
$s = phpser_serialize([$istore]);   // nested shared table -> duplicated walk
IsoGrow::$storeRef = null;
$rt = phpser_unserialize($s);
echo (is_string($s) && ($rt[0]['a'] ?? null) === 1
    && (($rt[0]['obj']->v ?? null) === 1) && !isset($rt[0]['g0']))
    ? "dup_isolation_snapshot OK\n" : "dup_isolation_snapshot FAIL\n";

// --- Debug-safe TABLE row-pin variant. Four identical rows share one array
//     (columnar path duplicates each shared row before running hooks); the
//     cell hook unsets a key on the shared row through a reference alias.
//     The snapshot must keep the key the original lost.
#[\AllowDynamicProperties]
class IsoCell {
    public static $rowRef = null;
    public function __serialize(): array {
        $r = &self::$rowRef;
        unset($r['b']);
        return ['x' => 1];
    }
}
$irow = ['a' => new IsoCell(), 'b' => str_repeat('y', 64)];
IsoCell::$rowRef = &$irow;
$trows = [$irow, $irow, $irow, $irow];
$st = phpser_serialize($trows);
IsoCell::$rowRef = null;
$tt = phpser_unserialize($st);
$tok = is_string($st) && is_array($tt) && count($tt) === 4 && !isset($irow['b']);
foreach ($tt as $row) {
    if (($row['b'] ?? null) !== str_repeat('y', 64)
        || (($row['a']->x ?? null) !== 1)) { $tok = false; break; }
}
echo $tok ? "table_row_pin_snapshot OK\n" : "table_row_pin_snapshot FAIL\n";
?>
--EXPECT--
shared_holey_assoc OK
shared_holey_packed OK
shared_holey_objrow OK
plain_roundtrip OK
dup_isolation_snapshot OK
table_row_pin_snapshot OK
