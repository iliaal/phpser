--TEST--
phpser: deeper reference shapes ported from ext/standard typed_property_ref* + bug70172/70213
--EXTENSIONS--
phpser
--FILE--
<?php

// --- Ref into a typed property: must round-trip with type intact. ---
class TypedRefs {
    public int $a;
    public $b;
}
$t = new TypedRefs();
$t->a = 1;
$t->b = &$t->a;  // b aliases a
$rt = phpser_unserialize(phpser_serialize($t));
$rt->b = 99;
echo ($rt->a === 99) ? "typed_ref_alias OK\n" : "typed_ref_alias FAIL ({$rt->a})\n";

// --- Two distinct refs in the same array: each must be its own zend_reference. ---
$x = 10; $y = 20;
$arr = [&$x, &$x, &$y, &$y];
$rt = phpser_unserialize(phpser_serialize($arr));
$rt[0] = 100;
$rt[2] = 200;
$ok = $rt[1] === 100 && $rt[3] === 200 && $rt[0] !== $rt[2];
echo $ok ? "two_refs OK\n" : "two_refs FAIL\n";

// --- Ref shared between object property and array slot, mutate via property. ---
class Holder { public $v; }
$h = new Holder();
$val = "before";
$h->v = &$val;
$pkg = ['holder' => $h, 'alias' => &$val];
$rt = phpser_unserialize(phpser_serialize($pkg));
$rt['holder']->v = "after";  // mutate through object prop
echo ($rt['alias'] === "after") ? "ref_via_prop OK\n" : "ref_via_prop FAIL ({$rt['alias']})\n";

// --- Ref through nested arrays: deep alias survives. ---
$leaf = "leaf";
$inner = ['ref' => &$leaf];
$outer = ['a' => $inner, 'b' => &$leaf];
$rt = phpser_unserialize(phpser_serialize($outer));
$rt['b'] = "changed";
echo ($rt['a']['ref'] === "changed") ? "nested_ref OK\n" : "nested_ref FAIL\n";

// --- Ref to a ref: PHP collapses these (no nested IS_REFERENCE). ---
$x = 7;
$r1 = &$x;
$r2 = &$r1;  // still aliases $x
$arr = [&$r1, &$r2];
$rt = phpser_unserialize(phpser_serialize($arr));
$rt[0] = 42;
echo ($rt[1] === 42) ? "ref_to_ref OK\n" : "ref_to_ref FAIL\n";

// --- Object property that's both a ref AND points at the parent (cycle through ref). ---
class Box {
    public $parent;
    public string $name = "";
}
$box = new Box();
$box->name = "B1";
$alias = $box;
$box->parent = &$alias;  // ref to outer alias, which IS the box
$rt = phpser_unserialize(phpser_serialize($box));
// $rt->parent should be a ref whose deref-value is an object with same identity as $rt.
// Mutating through the ref's value must reach back through identity preservation.
echo ($rt->parent->name === "B1") ? "ref_cycle OK\n" : "ref_cycle FAIL\n";

// --- Failed-serialize object referenced twice: PHP emits N; for both slots.
// Our equivalent: NOT_SERIALIZABLE classes emit TAG_NULL, no id claimed. ---
$f = function() {};  // closure — NOT_SERIALIZABLE
$arr = [$f, $f];
$rt = phpser_unserialize(phpser_serialize($arr));
echo ($rt[0] === null && $rt[1] === null) ? "fail_ser_refs OK\n" : "fail_ser_refs FAIL\n";

// --- Long shared-ref chain: stress id table growth via refs. ---
$shared = "value";
$arr = [];
for ($i = 0; $i < 200; $i++) $arr[] = &$shared;
$rt = phpser_unserialize(phpser_serialize($arr));
$rt[0] = "mutated";
$ok = $rt[199] === "mutated" && $rt[100] === "mutated";
echo $ok ? "long_ref_chain OK\n" : "long_ref_chain FAIL\n";

?>
--EXPECT--
typed_ref_alias OK
two_refs OK
ref_via_prop OK
nested_ref OK
ref_to_ref OK
ref_cycle OK
fail_ser_refs OK
long_ref_chain OK
