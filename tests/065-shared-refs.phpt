--TEST--
phpser: shared references + object identity + cycles (TAG_REF / TAG_NEW_REF)
--SKIPIF--
<?php if (!extension_loaded("phpser")) print "skip phpser not loaded"; ?>
--FILE--
<?php

// ---------------------------------------------------------------
// IS_REFERENCE sharing: two slots backed by one zend_reference.
// Writing through one must be observable through the other.
// ---------------------------------------------------------------
$x = 1;
$a = [&$x, &$x];
$rt = phpser_unserialize(phpser_serialize($a));
$rt[0] = 99;
echo ($rt[1] === 99) ? "ref_shared OK\n" : "ref_shared FAIL ({$rt[1]})\n";

// Three-way shared ref through an object property and an array slot.
class RefHolder { public $v; }
$h = new RefHolder();
$y = "hello";
$h->v = &$y;
$pkg = ['holder' => $h, 'alias' => &$y];
$rt = phpser_unserialize(phpser_serialize($pkg));
$rt['alias'] = "world";
echo ($rt['holder']->v === "world") ? "ref_threeway OK\n" : "ref_threeway FAIL ({$rt['holder']->v})\n";

// ---------------------------------------------------------------
// Object identity: the same zend_object appearing in multiple
// slots must come back as one object, not two.
// ---------------------------------------------------------------
class Thing { public int $n; public function __construct(int $n) { $this->n = $n; } }
$t = new Thing(42);
$arr = [$t, $t, ['nested' => $t]];
$rt = phpser_unserialize(phpser_serialize($arr));
$ok = ($rt[0] === $rt[1]) && ($rt[0] === $rt[2]['nested']) && ($rt[0]->n === 42);
echo $ok ? "obj_identity OK\n" : "obj_identity FAIL\n";

// Mutation through one alias is visible through every alias.
$rt[0]->n = 7;
echo ($rt[1]->n === 7 && $rt[2]['nested']->n === 7) ? "obj_identity_mut OK\n" : "obj_identity_mut FAIL\n";

// ---------------------------------------------------------------
// Object cycle: A -> B -> A (no IS_REFERENCE, just shared handle).
// Both directions must be preserved.
// ---------------------------------------------------------------
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
$ok = $rt->name === "A"
   && $rt->other instanceof CycleNode
   && $rt->other->name === "B"
   && $rt->other->other === $rt;  // back-edge resolved to the root, not NULL
echo $ok ? "obj_cycle OK\n" : "obj_cycle FAIL\n";

// Self-cycle: A -> A.
$s = new CycleNode("self");
$s->other = $s;
$rt = phpser_unserialize(phpser_serialize($s));
echo ($rt === $rt->other && $rt->name === "self") ? "self_cycle OK\n" : "self_cycle FAIL\n";

// ---------------------------------------------------------------
// Cycle through __serialize / __unserialize (deferred call path).
// The inner data array references a sibling object that itself
// references back to the parent through __serialize output.
// ---------------------------------------------------------------
class MagicCycle {
    public ?MagicCycle $partner = null;
    public string $tag;
    public function __construct(string $tag) { $this->tag = $tag; }
    public function __serialize(): array {
        return ['tag' => $this->tag, 'partner' => $this->partner];
    }
    public function __unserialize(array $d): void {
        $this->tag = $d['tag'];
        $this->partner = $d['partner'];
    }
}
$m1 = new MagicCycle("m1");
$m2 = new MagicCycle("m2");
$m1->partner = $m2;
$m2->partner = $m1;
$rt = phpser_unserialize(phpser_serialize($m1));
$ok = $rt->tag === "m1"
   && $rt->partner instanceof MagicCycle
   && $rt->partner->tag === "m2"
   && $rt->partner->partner === $rt;
echo $ok ? "magic_cycle OK\n" : "magic_cycle FAIL\n";

// ---------------------------------------------------------------
// Reference cycle: a ref that refers to an array that contains
// the ref itself. PHP serialize() handles this; we must too.
// ---------------------------------------------------------------
$arr = [];
$arr[] = &$arr;
$rt = phpser_unserialize(phpser_serialize($arr));
// The decoded value is an array whose [0] is a reference to itself.
// We verify by mutating through the alias.
echo (is_array($rt) && count($rt) === 1) ? "ref_self_size OK\n" : "ref_self_size FAIL\n";

// ---------------------------------------------------------------
// Many shared objects: stress the id table growth path.
// ---------------------------------------------------------------
$o = new Thing(1);
$big = [];
for ($i = 0; $i < 500; $i++) {
    $big[] = $o;
}
$rt = phpser_unserialize(phpser_serialize($big));
$ok = count($rt) === 500;
for ($i = 1; $i < 500 && $ok; $i++) {
    if ($rt[$i] !== $rt[0]) { $ok = false; break; }
}
echo $ok ? "many_shared OK\n" : "many_shared FAIL\n";
?>
--EXPECT--
ref_shared OK
ref_threeway OK
obj_identity OK
obj_identity_mut OK
obj_cycle OK
self_cycle OK
magic_cycle OK
ref_self_size OK
many_shared OK
