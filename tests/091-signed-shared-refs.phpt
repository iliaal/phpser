--TEST--
phpser: signed (trusted) shared refs, object identity, cycles — ASAN coverage for the id-table pin-skip
--EXTENSIONS--
phpser
--FILE--
<?php
// Commit "Decode hot loops skip the recursive wrapper for scalar tags" also
// stopped GC-pinning every object registered in the id table on the
// HMAC-authenticated (trusted) decode path: a registered object stays alive
// through its owning zval instead of an extra addref. 065-shared-refs covers
// these shapes on the untrusted path only; this mirrors them through
// phpser_unserialize_signed so the ASAN lane exercises the unpinned path for
// shared objects, back-references, and cycles.
$key = str_repeat("s", 32);

function sround($v) {
    global $key;
    return phpser_unserialize_signed(phpser_serialize_signed($v, $key), $key);
}

// IS_REFERENCE sharing (references stay pinned even on the trusted path).
$x = 1;
$rt = sround([&$x, &$x]);
$rt[0] = 99;
echo ($rt[1] === 99) ? "ref_shared OK\n" : "ref_shared FAIL\n";

// Object identity: one zend_object in several slots comes back as one object.
// Typed, fully-initialized props also route these through TAG_OBJECT_SLOTS, so
// the first occurrence claims an id and the rest decode as TAG_REF to it.
class Thing { public int $n; public function __construct(int $n) { $this->n = $n; } }
$t = new Thing(42);
$rt = sround([$t, $t, ['nested' => $t]]);
$ok = ($rt[0] === $rt[1]) && ($rt[0] === $rt[2]['nested']) && ($rt[0]->n === 42);
echo $ok ? "obj_identity OK\n" : "obj_identity FAIL\n";
$rt[0]->n = 7;
echo ($rt[1]->n === 7 && $rt[2]['nested']->n === 7) ? "obj_identity_mut OK\n" : "obj_identity_mut FAIL\n";

// Object cycle A<->B: the back-ref must resolve to the unpinned root object.
class Node { public ?Node $other = null; public string $name; public function __construct(string $n) { $this->name = $n; } }
$a = new Node("A");
$b = new Node("B");
$a->other = $b;
$b->other = $a;
$rt = sround($a);
$ok = $rt->name === "A" && $rt->other instanceof Node
   && $rt->other->name === "B" && $rt->other->other === $rt;
echo $ok ? "obj_cycle OK\n" : "obj_cycle FAIL\n";
$rt->other->other = null; // break the decoded cycle for the leak checker
$a->other = $b->other = null; // and the source cycle

// Self-cycle A -> A.
$s = new Node("self");
$s->other = $s;
$rt = sround($s);
echo ($rt === $rt->other && $rt->name === "self") ? "self_cycle OK\n" : "self_cycle FAIL\n";
$rt->other = null;
$s->other = null;

// Cycle through __serialize / __unserialize (deferred call + back-ref).
class MagicCycle {
    public ?MagicCycle $partner = null;
    public string $tag;
    public function __construct(string $t) { $this->tag = $t; }
    public function __serialize(): array { return ['tag' => $this->tag, 'partner' => $this->partner]; }
    public function __unserialize(array $d): void { $this->tag = $d['tag']; $this->partner = $d['partner']; }
}
$m1 = new MagicCycle("m1");
$m2 = new MagicCycle("m2");
$m1->partner = $m2;
$m2->partner = $m1;
$rt = sround($m1);
$ok = $rt->tag === "m1" && $rt->partner instanceof MagicCycle
   && $rt->partner->tag === "m2" && $rt->partner->partner === $rt;
echo $ok ? "magic_cycle OK\n" : "magic_cycle FAIL\n";
$rt->partner->partner = null;
$m1->partner = $m2->partner = null; // break the source cycle too

// Many shared objects: id-table growth on the unpinned path.
$o = new Thing(1);
$big = [];
for ($i = 0; $i < 500; $i++) $big[] = $o;
$rt = sround($big);
$ok = count($rt) === 500;
for ($i = 1; $i < 500 && $ok; $i++) {
    if ($rt[$i] !== $rt[0]) { $ok = false; break; }
}
echo $ok ? "many_shared OK\n" : "many_shared FAIL\n";

gc_collect_cycles();
?>
--EXPECT--
ref_shared OK
obj_identity OK
obj_identity_mut OK
obj_cycle OK
self_cycle OK
magic_cycle OK
many_shared OK
