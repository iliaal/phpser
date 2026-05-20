--TEST--
phpser: documented limitations — features not yet supported, no-crash contract
--SKIPIF--
<?php if (!extension_loaded("phpser")) print "skip phpser not loaded"; ?>
--FILE--
<?php

// This file pins down behavior on shapes phpser DOESN'T fully support.
// Each case must (a) not crash, and (b) produce *something*. When we add
// proper support for one of these features the asserts below will need
// to be tightened — that's intentional. The file exists so an
// accidental regression that, say, segfaults on Serializable can't ship
// silently.

// --- ArrayObject (igbinary_bug54662 shape) ---
// SPL ArrayObject has a custom internal serializer. We don't honor
// ce->serialize, __serialize, or Serializable yet — so the internal
// storage doesn't round-trip. Contract for now: no crash, object
// instance comes back as the same class.
class StorageBug054662 { public $storage = "a string"; }
$collection = new ArrayObject();
$collection->append(new StorageBug054662());
$rt = phpser_unserialize(phpser_serialize($collection));
echo $rt instanceof ArrayObject ? "arrayobject_class OK\n" : "arrayobject_class FAIL\n";

// --- IS_REFERENCE shared between values ---
// PHP serialize emits two refs sharing one zend_reference; ours flattens
// each ref-site to a copy, so post-decode the two slots are independent.
// Document: NOT shared on round-trip.
$x = 1;
$a = [&$x, &$x];
$rt = phpser_unserialize(phpser_serialize($a));
$rt[0] = 99;
echo $rt[1] === 1 ? "ref_flattened OK\n" : "ref_flattened FAIL\n";

// --- __serialize / __unserialize magic methods (PHP 7.4+) ---
// Not called yet. The default get_properties path captures declared
// props, so the object round-trips structurally — but any custom logic
// in __serialize is ignored.
class WithMagicSer {
    public int $x = 10;
    public function __serialize(): array { return ['magic_called' => true]; }
    public function __unserialize(array $data): void { $this->x = 999; }
}
$rt = phpser_unserialize(phpser_serialize(new WithMagicSer()));
// We bypass __serialize; we read $x via get_properties; we bypass
// __unserialize; constructor doesn't fire; $x stays at the encoded 10.
echo $rt->x === 10 ? "magic_methods_bypassed OK\n" : "magic_methods_bypassed FAIL\n";

// --- Object cycle via property pointer (no IS_REFERENCE) ---
// Cycle detection via visited_objs: second visit emits NULL. The cycle
// is broken; the structure decodes successfully but loses the back-edge.
class CycleNode {
    public ?CycleNode $other = null;
    public string $name;
    public function __construct(string $name) { $this->name = $name; }
}
$a = new CycleNode("A");
$b = new CycleNode("B");
$a->other = $b;
$b->other = $a;
$rt = phpser_unserialize(phpser_serialize($a));
echo ($rt->name === "A" && $rt->other instanceof CycleNode && $rt->other->name === "B")
    ? "obj_cycle_break OK\n" : "obj_cycle_break FAIL\n";
// Back-edge from b to a was emitted as NULL because $a was already visited.
echo ($rt->other->other === null) ? "obj_cycle_null_backedge OK\n" : "obj_cycle_null_backedge FAIL\n";

// --- Closures and resources — emit NULL, no crash ---
$f = function () { return 42; };
$rt = phpser_unserialize(phpser_serialize($f));
echo $rt === null ? "closure_null OK\n" : "closure_null FAIL\n";

$r = fopen('php://memory', 'r');
$rt = phpser_unserialize(phpser_serialize($r));
echo $rt === null ? "resource_null OK\n" : "resource_null FAIL\n";
fclose($r);
?>
--EXPECT--
arrayobject_class OK
ref_flattened OK
magic_methods_bypassed OK
obj_cycle_break OK
obj_cycle_null_backedge OK
closure_null OK
resource_null OK
