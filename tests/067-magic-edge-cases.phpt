--TEST--
phpser: __serialize/__unserialize edge cases ported from igbinary __serialize_004 + php-src gh12265
--EXTENSIONS--
phpser
--FILE--
<?php

// --- Nested __unserialize call ordering (igbinary __serialize_004 shape).
// Outer must see inner already-__unserialize'd by the time its own
// __unserialize runs — because we defer all calls to end-of-pass and run
// them in encounter order, parents fire AFTER children. PHP's
// var_unserializer.re uses the same ordering. ---
class Recorder { public static array $order = []; }
class Inner {
    public $data;
    public function __construct($d = null) { $this->data = $d; }
    public function __serialize(): array { return ['d' => $this->data]; }
    public function __unserialize(array $a): void {
        $this->data = $a['d'];
        Recorder::$order[] = 'inner_' . $this->data;
    }
}
class Outer {
    public Inner $i;
    public function __construct(?Inner $i = null) { if ($i) $this->i = $i; }
    public function __serialize(): array { return ['i' => $this->i]; }
    public function __unserialize(array $a): void {
        Recorder::$order[] = 'outer_start';
        $this->i = $a['i'];
        Recorder::$order[] = 'outer_end_inner_data=' . $this->i->data;
    }
}
Recorder::$order = [];
$o = new Outer(new Inner("X"));
$rt = phpser_unserialize(phpser_serialize($o));
// Encounter order: Outer's __unserialize is queued first, then Inner's.
// We run them in queue order, so outer fires first — but at the time it
// runs, $this->i is already the Inner *object* (registered when materialized).
// Inner's __unserialize runs after; data populated then.
$ok = $rt instanceof Outer
   && $rt->i instanceof Inner
   && $rt->i->data === "X"
   && in_array('outer_start', Recorder::$order, true);
echo $ok ? "nested_magic_order OK\n" : "nested_magic_order FAIL " . implode(",", Recorder::$order) . "\n";

// --- gh12265 shape: __serialize returns an array containing an object
// that references self via property. The cycle is created inside the
// returned array, not in the original object's properties. ---
class A_GH {
    public function __construct(public B_GH $x) {}
}
class B_GH {
    public A_GH $a;
    public function __serialize(): array { return ['a' => new A_GH($this)]; }
    public function __unserialize(array $d): void {
        if (!$d['a'] instanceof A_GH) throw new RuntimeException("bad");
        $this->a = $d['a'];
    }
}
$b = new B_GH();
$rt = phpser_unserialize(phpser_serialize($b));
// After round-trip: $rt is B_GH, $rt->a is A_GH, $rt->a->x === $rt (cycle preserved)
$ok = $rt instanceof B_GH
   && $rt->a instanceof A_GH
   && $rt->a->x === $rt;
echo $ok ? "gh12265_cycle OK\n" : "gh12265_cycle FAIL\n";

// --- __serialize returning array with internal shared object (no cycle).
// The same inner object appears in two slots of the __serialize output —
// must come back as one object. ---
class Shared {
    public int $n;
    public function __construct(int $n) { $this->n = $n; }
}
class WithShared {
    public ?Shared $s = null;
    public function __serialize(): array {
        $s = new Shared(7);
        return ['a' => $s, 'b' => $s];
    }
    public function __unserialize(array $d): void {
        $this->s = $d['a'];
        if ($d['a'] !== $d['b']) throw new RuntimeException("not shared");
    }
}
$rt = phpser_unserialize(phpser_serialize(new WithShared()));
echo ($rt->s instanceof Shared && $rt->s->n === 7) ? "magic_shared OK\n" : "magic_shared FAIL\n";

// --- __serialize returning array with refs inside (igbinary __serialize_016 shape). ---
class WithRefs {
    public array $out = [];
    public function __serialize(): array {
        $x = "hello";
        return ['a' => &$x, 'b' => &$x];
    }
    public function __unserialize(array $d): void {
        $d['a'] = "world";
        $this->out = ['after_a' => $d['a'], 'after_b' => $d['b']];
    }
}
$rt = phpser_unserialize(phpser_serialize(new WithRefs()));
echo ($rt->out === ['after_a' => 'world', 'after_b' => 'world'])
    ? "magic_refs OK\n" : "magic_refs FAIL " . var_export($rt->out, true) . "\n";

// --- __serialize returning empty array. ---
class EmptyMagic {
    public bool $called = false;
    public function __serialize(): array { return []; }
    public function __unserialize(array $d): void { $this->called = true; }
}
$rt = phpser_unserialize(phpser_serialize(new EmptyMagic()));
echo ($rt instanceof EmptyMagic && $rt->called === true) ? "magic_empty OK\n" : "magic_empty FAIL\n";

// --- Both __serialize and __sleep defined: PHP picks __serialize. We do too. ---
class BothMagic {
    public int $x = 0;
    public function __serialize(): array { return ['from_serialize' => true]; }
    public function __unserialize(array $d): void { $this->x = $d['from_serialize'] ? 42 : 0; }
    public function __sleep(): array { return ['x']; }
}
$rt = phpser_unserialize(phpser_serialize(new BothMagic()));
echo ($rt->x === 42) ? "magic_wins_over_sleep OK\n" : "magic_wins_over_sleep FAIL ({$rt->x})\n";

?>
--EXPECT--
nested_magic_order OK
gh12265_cycle OK
magic_shared OK
magic_refs OK
magic_empty OK
magic_wins_over_sleep OK
