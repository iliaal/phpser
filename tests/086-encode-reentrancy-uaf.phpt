--TEST--
phpser: encode-side UAF — a property's __serialize/__sleep grows the object's property table mid-walk
--EXTENSIONS--
phpser
--FILE--
<?php

// =====================================================================
// The object slow-path property walk cached props->arData / end pointers
// and called encode_value() inside the loop. A property value's
// __serialize (or __sleep, or a destructor) can run there and add dynamic
// properties to the SAME object, reallocating obj->properties in place —
// the cached bucket pointer then dangles (UAF read at enc_obj_prop_val).
// The fix takes a ref on the table via zend_get_properties_for so the
// mutation COW-separates instead of reallocating under the iterator,
// exactly as native serialize() does. Under valgrind/ASan the bug crashes;
// without instrumentation it silently corrupts the emitted properties.
// We assert the emitted snapshot round-trips to the pre-mutation state and
// matches native serialize(), a sufficient proxy either way.
// =====================================================================

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

$rt = phpser_unserialize(phpser_serialize($make()));
// The properties that existed before the mutation must survive intact; the
// dynamic props added during __serialize belong to the COW-separated copy
// and are (correctly) absent from the point-in-time snapshot — same as native.
$ok = $rt instanceof C_reent
    && $rt->second === "second-value"
    && $rt->third === "third-value"
    && $rt->preexisting === "force-properties-HT"
    && ($rt->first->v ?? null) === 1
    && !isset($rt->dyn0);
echo $ok ? "encode_reentrancy OK\n" : "encode_reentrancy FAIL\n";

// Parity with native on a fresh instance: neither side captures the
// mid-serialize additions in the emitted snapshot.
$nc = unserialize(serialize($make()));
echo (($nc->second ?? null) === "second-value" && !isset($nc->dyn0))
    ? "native_parity OK\n" : "native_parity FAIL\n";

// =====================================================================
// __sleep variant: a listed property's value grows the object mid-walk.
// The __sleep path iterates the returned name array (a fresh temporary)
// and re-resolves each property, so it was never vulnerable — pin it.
// =====================================================================
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
echo (($rt->b ?? null) === "b-value" && ($rt->a->v ?? null) === 2)
    ? "sleep_reentrancy OK\n" : "sleep_reentrancy FAIL\n";

?>
--EXPECT--
encode_reentrancy OK
native_parity OK
sleep_reentrancy OK
