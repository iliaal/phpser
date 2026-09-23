--TEST--
phpser: encode-side UAF: a property's __serialize/__sleep grows the object's property table mid-walk
--EXTENSIONS--
phpser
--FILE--
<?php

// A nested hook grows the object during its property walk. The emitted
// snapshot must retain pre-mutation properties, matching native serialize().

#[\AllowDynamicProperties]
class C_reent {
    public $first;
    public $second;
    public $third;
}
#[\AllowDynamicProperties]
class D_grow {
    public $parent;
    public function __serialize(): array {
        // Force obj->properties to resize while C_reent's walk holds a
        // bucket pointer into it.
        for ($i = 0; $i < 128; $i++) {
            $this->parent->{"dyn$i"} = str_repeat("x", 16);
        }
        return ['v' => 1];
    }
}

// __serialize mutates the live object, so use a fresh instance per encoder.
$make = function () {
    $c = new C_reent();
    $c->preexisting = "force-properties-HT";  // materialize obj->properties -> slow path
    $c->first  = new D_grow();
    $c->first->parent = $c;
    $c->second = "second-value";
    $c->third  = "third-value";
    return $c;
};

// Keep each graph in a variable and cut its C->D->C cycle after use, so the
// objects free by refcount and don't linger as cyclic garbage for the leak
// checker (the ASAN lane runs LSAN before the shutdown cycle collector).
$c1 = $make();
$rt = phpser_unserialize(phpser_serialize($c1));
$c1->first->parent = null;
// The properties that existed before the mutation must survive intact; the
// dynamic props added during __serialize belong to the COW-separated copy
// and are (correctly) absent from the point-in-time snapshot, same as native.
$ok = $rt instanceof C_reent
    && $rt->second === "second-value"
    && $rt->third === "third-value"
    && $rt->preexisting === "force-properties-HT"
    && ($rt->first->v ?? null) === 1
    && !isset($rt->dyn0);
echo $ok ? "encode_reentrancy OK\n" : "encode_reentrancy FAIL\n";

// Parity with native on a fresh instance: neither side captures the
// mid-serialize additions in the emitted snapshot.
$c2 = $make();
$nc = unserialize(serialize($c2));
$c2->first->parent = null;
echo (($nc->second ?? null) === "second-value" && !isset($nc->dyn0))
    ? "native_parity OK\n" : "native_parity FAIL\n";

// A listed property's value grows the object during __sleep serialization.
#[\AllowDynamicProperties]
class S_sleep {
    public $a;
    public $b = "b-value";
    public function __sleep(): array { return ['a', 'b']; }
}
#[\AllowDynamicProperties]
class E_grow {
    public $parent;
    public function __serialize(): array {
        for ($i = 0; $i < 64; $i++) { $this->parent->{"z$i"} = $i; }
        return ['v' => 2];
    }
}
$s = new S_sleep();
$s->a = new E_grow();
$s->a->parent = $s;
$rt = phpser_unserialize(phpser_serialize($s));
$s->a->parent = null;  // cut the S<->E cycle
echo (($rt->b ?? null) === "b-value" && ($rt->a->v ?? null) === 2)
    ? "sleep_reentrancy OK\n" : "sleep_reentrancy FAIL\n";

gc_collect_cycles();

?>
--EXPECT--
encode_reentrancy OK
native_parity OK
sleep_reentrancy OK
