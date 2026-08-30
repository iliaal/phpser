--TEST--
phpser: encode-side UAF — a __serialize hook mutates the live ArrayObject storage it was handed out from (BUG-R2-C4-A1-H1 / BUG-R2-C4-A2-H1)
--DESCRIPTION--
ArrayObject/ArrayIterator::__serialize embed the object's LIVE internal
storage array in the retval by ZVAL_COPY (an addref, not a copy). phpser
walks that retval, so it walks a table still owned and writable by the
ArrayObject. The GC_TRY_ADDREF hold on the walked array only forces COW
separation for zval-level writers; SPL's write handlers mutate the shared
table in place through the refcount-blind zend_hash C-API (HT_ASSERT_RC1 is
ZEND_DEBUG-only), so the hold does not protect it:

  - An INSERT (offsetSet/append) reallocs the storage's arData under
    encode_hashtable's cached bucket iterator (sink BUG-R2-C4-A1-H1).
  - A DELETE (offsetUnset) of an RC-1 row frees that row's HashTable under
    enc_try_table's gathered col_cells pointers (sink BUG-R2-C4-A2-H1).
  - A row that is itself a second ArrayObject's storage is REALLOCATED in
    place by a sibling cell's hook, dangling the same col_cells pointers.

All are heap use-after-free. The fix closes the two sites that hold raw
pointers across user code:

  - encode_hashtable's generic per-element loops walk a private duplicate
    (enc_pin_walk -> zend_array_dup) of any shared (refcount>1) NESTED table
    before dispatching, so an in-place realloc of the original can't reach it.
  - enc_try_table pins every row across the columnar emit loop: it duplicates
    a shared (refcount>1) row and addrefs a sole-owned row, so a hook can
    neither realloc a shared row nor free an RC-1 row under the gather.

On a release build each turns into a freed-heap read that valgrind/ASAN flags;
without instrumentation the assertions still hold (encode completes, output
round-trips). The scenario cannot run on a debug build: the misbehaving SPL
write to the refcount>1 storage trips the engine's own HT_ASSERT_RC1 (active
only under ZEND_DEBUG) and aborts before the walk continues, so the test skips
there. The duplicate-path correctness that a debug/ASAN lane CAN exercise (no
in-place SPL write) lives in test 128.
--SKIPIF--
<?php
if (!extension_loaded("phpser")) die("skip phpser not loaded");
if (PHP_DEBUG) die("skip debug build aborts on HT_ASSERT_RC1 before the walk; release/valgrind only");
?>
--FILE--
<?php

// --- Sink A: hook INSERTS into the storage -> realloc under the generic walk.
class Grower {
    public static ?ArrayObject $ao = null;
    public function __serialize(): array {
        for ($i = 0; $i < 64; $i++) {
            self::$ao["grown$i"] = str_repeat("x", 32);
        }
        return ['v' => 1];
    }
}
$ao = new ArrayObject();
Grower::$ao = $ao;
$ao['obj'] = new Grower();
$ao['a'] = 1;
$ao['b'] = 2;
$s = phpser_serialize($ao);
Grower::$ao = null;
echo (is_string($s) && strlen($s) > 0) ? "insert_no_uaf OK\n" : "insert_no_uaf FAIL\n";
// The private duplicate is what got walked, so the original ArrayObject is
// untouched by the walk itself (the hook still grew it, that is user intent).
echo ($ao['a'] === 1 && $ao['b'] === 2) ? "insert_original_intact OK\n" : "insert_original_intact FAIL\n";

// --- Sink B: hook DELETES a row from the storage -> frees an RC-1 row under
//     the columnar col_cells gather.
class Cell {
    public static ?ArrayObject $ao = null;
    public function __serialize(): array {
        // Free row 0's HashTable while enc_try_table holds pointers into it.
        self::$ao->offsetUnset(0);
        return ['x' => 1];
    }
}
$row0 = ['a' => new Cell(), 'b' => str_repeat('y', 64)];
$row1 = ['a' => new Cell(), 'b' => str_repeat('z', 64)];
$ao2 = new ArrayObject([$row0, $row1]);
Cell::$ao = $ao2;
unset($row0, $row1); // each row's only owner is now the storage bucket (RC1)
$s2 = phpser_serialize($ao2);
Cell::$ao = null;
echo (is_string($s2) && strlen($s2) > 0) ? "delete_no_uaf OK\n" : "delete_no_uaf FAIL\n";

// --- ArrayIterator delete variant (same C __serialize handler as ArrayObject).
class CellIt {
    public static ?ArrayIterator $ai = null;
    public function __serialize(): array {
        self::$ai->offsetUnset(0);
        return ['x' => 1];
    }
}
$r0 = ['a' => new CellIt(), 'b' => str_repeat('y', 64)];
$r1 = ['a' => new CellIt(), 'b' => str_repeat('z', 64)];
$ai = new ArrayIterator([$r0, $r1]);
CellIt::$ai = $ai;
unset($r0, $r1);
$s3 = phpser_serialize($ai);
CellIt::$ai = null;
echo (is_string($s3) && strlen($s3) > 0) ? "iterator_delete_no_uaf OK\n" : "iterator_delete_no_uaf FAIL\n";

// --- Sink C: a rowset ROW is itself a second ArrayObject's live storage; a
//     sibling cell's hook grows that ArrayObject, reallocating the row's bucket
//     array under enc_try_table's col_cells gather. The per-row dup (shared
//     row) isolates the copy we gathered from.
class RowMut {
    public static ?ArrayObject $rowAO = null;
    public function __serialize(): array {
        for ($i = 0; $i < 64; $i++) self::$rowAO["g$i"] = str_repeat("x", 8);
        return ['h' => 1];
    }
}
$rowAO = new ArrayObject(['a' => new RowMut(), 'b' => 'tail1']);
RowMut::$rowAO = $rowAO;
$row0 = $rowAO->__serialize()[1];               // aliases rowAO's live storage (refcount>1)
$row1 = ['a' => new RowMut(), 'b' => 'tail2'];
$s4 = phpser_serialize([$row0, $row1]);         // columnar; row0 is shared storage
RowMut::$rowAO = null;
echo (is_string($s4) && strlen($s4) > 0) ? "shared_row_no_uaf OK\n" : "shared_row_no_uaf FAIL\n";

// --- Sink A nested: extracted storage wrapped one level deep still walks a
//     private duplicate (enc_pin_walk fires at depth > 1).
class NestMut {
    public static ?ArrayObject $ao = null;
    public function __serialize(): array {
        for ($i = 0; $i < 64; $i++) self::$ao["g$i"] = str_repeat("x", 32);
        return ['v' => 1];
    }
}
$nao = new ArrayObject();
NestMut::$ao = $nao;
$nao['obj'] = new NestMut();
$nao['a'] = 1;
$nstore = $nao->__serialize()[1];
$s5 = phpser_serialize([$nstore]);              // storage nested at depth 2 -> duplicated
NestMut::$ao = null;
echo (is_string($s5) && strlen($s5) > 0) ? "nested_extracted_no_uaf OK\n" : "nested_extracted_no_uaf FAIL\n";

?>
--EXPECT--
insert_no_uaf OK
insert_original_intact OK
delete_no_uaf OK
iterator_delete_no_uaf OK
shared_row_no_uaf OK
nested_extracted_no_uaf OK
