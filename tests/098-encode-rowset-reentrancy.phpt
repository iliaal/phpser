--TEST--
phpser: columnar TAG_TABLE encode is memory-safe when a cell hook mutates a sibling row (ASAN canary)
--DESCRIPTION--
enc_try_table gathers raw &b->val pointers across every row, then emits
column-major, running user code (__serialize) for MIXED object columns. A cell
hook that reaches a sibling row must not dangle those pointers. Safety rests on
(a) reference rows being excluded from the columnar path and (b) array
copy-on-write separating any second-handle mutation. This is the regression
canary for that invariant — run under the ASAN lane it turns a UAF into an
invalid-read abort; without ASAN it still asserts correct output.
--EXTENSIONS--
phpser
--FILE--
<?php

class Cell {
    public int $tag;
    public static $outer = null;
    public function __construct(int $t) { $this->tag = $t; }
    public function __serialize(): array {
        // Reach a sibling row of the rowset being serialized and grow it,
        // forcing that inner array to realloc. self::$outer holds a second
        // handle (refcount >= 2), so COW separates the copy — the encoder's
        // rowset is untouched. If the columnar gather ever dropped this
        // guarantee, the cached &b->val for row 1 would dangle here.
        if (self::$outer !== null) {
            self::$outer[1]['grown'] = str_repeat('x', 128);
        }
        return ['tag' => $this->tag];
    }
    public function __unserialize(array $d): void { $this->tag = $d['tag']; }
}

// Homogeneous string-keyed rowset, >=2 rows, matching schema -> columnar path.
// Column 'a' is objects (MIXED, runs __serialize); column 'b' is strings.
$rowset = [
    ['a' => new Cell(10), 'b' => 'r0'],
    ['a' => new Cell(20), 'b' => 'r1'],
    ['a' => new Cell(30), 'b' => 'r2'],
];
Cell::$outer = $rowset; // second handle -> COW protects the encoder's copy

$s  = phpser_serialize($rowset);
$rt = phpser_unserialize($s);
Cell::$outer = null;

$ok = is_array($rt) && count($rt) === 3
    && $rt[0]['a'] instanceof Cell && $rt[0]['a']->tag === 10
    && $rt[2]['a']->tag === 30
    && $rt[1]['b'] === 'r1';
echo $ok ? "sibling_mutation OK\n" : "sibling_mutation FAIL\n";

// The encoder's original rowset must not have gained the 'grown' key (the
// mutation landed on the COW-separated copy).
echo isset($rowset[1]['grown']) ? "cow_isolation FAIL\n" : "cow_isolation OK\n";

// Reference-row variant: a `&` row makes the bucket IS_REFERENCE, which
// enc_match_rowset_schema rejects, so the columnar path is skipped entirely.
// Must still round-trip.
$r0 = ['a' => 1, 'b' => 2];
$r1 = ['a' => 3, 'b' => 4];
$refset = [$r0, &$r1];
$rt2 = phpser_unserialize(phpser_serialize($refset));
echo ($rt2 === [['a' => 1, 'b' => 2], ['a' => 3, 'b' => 4]])
    ? "reference_row OK\n" : "reference_row FAIL\n";

?>
--EXPECT--
sibling_mutation OK
cow_isolation OK
reference_row OK
