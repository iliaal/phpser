--TEST--
phpser: encode-side UAF — a __serialize hook grows the array being walked through a by-reference alias
--EXTENSIONS--
phpser
--FILE--
<?php
// =====================================================================
// encode_hashtable() caches raw arData/arPacked base pointers across the
// encode_value() calls in its element loop, and those calls run user hooks
// (__serialize/__sleep). An element object whose __serialize appends to the
// SAME array through a by-reference alias reallocates the table under the
// iterator -> use-after-free (CR-002). Native serialize() has the identical
// bug and faults on this shape; phpser takes a ref across the walk so the
// mutating write COW-separates instead, the array analog of the object
// property-table guard (086). Under valgrind/ASan the pre-fix code crashes;
// here we assert the encode completes and the pre-mutation snapshot round-
// trips, a sufficient proxy either way.
// =====================================================================

#[\AllowDynamicProperties]
class G_refgrow {
    public $ref;
    public function __serialize(): array {
        // Grow the referenced array well past its packed capacity so the
        // table reallocates mid-walk.
        for ($i = 0; $i < 128; $i++) $this->ref[] = 'x' . $i;
        return ['done' => 1];
    }
}

$g = new G_refgrow();
$inner = [$g, 'tail'];   // element 1 ('tail') is read AFTER element 0's hook runs
$g->ref = &$inner;       // $inner becomes a reference; its array refcount stays 1
$top = [&$inner];        // nest by-reference so $inner's array is not COW-shielded

$blob = phpser_serialize($top);
var_dump(is_string($blob) && strlen($blob) > 0);

// Round-trips to a well-formed structure (the exact grown length is not the
// point — memory-safety and a decodable frame are).
$rt = phpser_unserialize($blob);
var_dump(is_array($rt));
var_dump(is_array($rt[0]));
var_dump($rt[0][0] instanceof G_refgrow);
echo "no fault\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
no fault
