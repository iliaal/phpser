--TEST--
phpser: __sleep filters props on encode; __wakeup fires after decode
--EXTENSIONS--
phpser
--FILE--
<?php

// --- __sleep returns a subset of declared props: only those land in the
// payload. Unselected props decode as their declared default. ---
class Filter {
    public int $kept = 1;
    public int $dropped = 99;
    public function __sleep(): array { return ['kept']; }
}
$f = new Filter();
$f->kept = 7;
$f->dropped = 7;
$rt = phpser_unserialize(phpser_serialize($f));
echo ($rt->kept === 7 && $rt->dropped === 99)
    ? "sleep_filter OK\n" : "sleep_filter FAIL (kept={$rt->kept} dropped={$rt->dropped})\n";

// --- __sleep with protected/private declared props: the unmangled name
// in the sleep array resolves via ce->properties_info to the right slot. ---
class Visi {
    public int $pub = 0;
    protected int $prot = 0;
    private int $priv = 0;
    public function set(int $pub, int $prot, int $priv): void {
        $this->pub = $pub;
        $this->prot = $prot;
        $this->priv = $priv;
    }
    public function get(): array {
        return [$this->pub, $this->prot, $this->priv];
    }
    public function __sleep(): array { return ['pub', 'prot', 'priv']; }
}
$v = new Visi();
$v->set(1, 2, 3);
$rt = phpser_unserialize(phpser_serialize($v));
echo ($rt instanceof Visi && $rt->get() === [1, 2, 3])
    ? "sleep_visibility OK\n" : "sleep_visibility FAIL " . json_encode($rt->get()) . "\n";

// --- __sleep returning a name not declared on the class: skip silently
// (PHP warns; we don't propagate the warning, but result must be safe). ---
class Bogus {
    public int $real = 5;
    public function __sleep(): array { return ['real', 'nonexistent']; }
}
$rt = phpser_unserialize(phpser_serialize(new Bogus()));
echo ($rt->real === 5) ? "sleep_unknown_skip OK\n" : "sleep_unknown_skip FAIL\n";

// --- __sleep returning a dynamic property name: PHP includes it in the
// payload by looking up the unmangled name in obj->properties. ---
#[\AllowDynamicProperties]
class WithDyn {
    public int $declared = 1;
    public function __sleep(): array { return ['declared', 'extra']; }
}
$o = new WithDyn();
$o->extra = "dyn";
$rt = phpser_unserialize(phpser_serialize($o));
echo ($rt->declared === 1 && $rt->extra === "dyn")
    ? "sleep_dynamic OK\n" : "sleep_dynamic FAIL\n";

// --- sleep_uninitialized_typed_prop shape: a typed prop is currently
// IS_UNDEF (no value assigned, no default). Skip silently — PHP warns
// but doesn't fail. ---
class TypedUninit {
    public int $x;
    public int $y;
    public function __sleep(): array { return ['x', 'y']; }
    public function setX(int $n): void { $this->x = $n; }
}
$u = new TypedUninit();
$u->setX(11);  // y stays uninitialized
$rt = phpser_unserialize(phpser_serialize($u));
echo ($rt->x === 11 && !isset($rt->y))
    ? "sleep_uninit OK\n" : "sleep_uninit FAIL\n";

// --- __sleep throws: must propagate, no NULL-but-no-exception. ---
class ThrowsOnSleep {
    public function __sleep(): array { throw new RuntimeException("sleep boom"); }
}
$caught = false;
try {
    phpser_serialize(new ThrowsOnSleep());
} catch (RuntimeException $e) {
    $caught = ($e->getMessage() === "sleep boom");
}
echo $caught ? "sleep_throws OK\n" : "sleep_throws FAIL\n";

// --- __wakeup is called after the object decodes. ---
class Wake {
    public int $n = 0;
    public bool $woke = false;
    public function __wakeup(): void { $this->woke = true; $this->n *= 10; }
}
$w = new Wake();
$w->n = 4;
$rt = phpser_unserialize(phpser_serialize($w));
echo ($rt->woke === true && $rt->n === 40) ? "wakeup_called OK\n" : "wakeup_called FAIL\n";

// --- __wakeup runs AFTER the whole graph is materialized: a wakeup hook
// on object A can see object B (its sibling in the parent) even when B
// also has a wakeup hook that hasn't fired yet. ---
class WakeRec { public static array $log = []; public string $name = ""; public ?WakeRec $sibling = null; }
class WakeA extends WakeRec {
    public function __wakeup(): void {
        WakeRec::$log[] = "A_sees_sibling=" . ($this->sibling ? $this->sibling->name : "null");
    }
}
class WakeB extends WakeRec {
    public function __wakeup(): void {
        WakeRec::$log[] = "B_sees_sibling=" . ($this->sibling ? $this->sibling->name : "null");
    }
}
$a = new WakeA(); $a->name = "A";
$b = new WakeB(); $b->name = "B";
$a->sibling = $b;
$b->sibling = $a;
WakeRec::$log = [];
phpser_unserialize(phpser_serialize([$a, $b]));
// Both wakeup hooks must see their sibling already materialized.
$ok = in_array("A_sees_sibling=B", WakeRec::$log, true)
   && in_array("B_sees_sibling=A", WakeRec::$log, true);
echo $ok ? "wakeup_graph_complete OK\n" : "wakeup_graph_complete FAIL " . implode(",", WakeRec::$log) . "\n";

// --- Class defines BOTH __serialize and __sleep: __serialize wins. ---
class BothEncode {
    public int $x = 0;
    public function __serialize(): array { return ['from' => 'serialize']; }
    public function __unserialize(array $d): void { $this->x = $d['from'] === 'serialize' ? 100 : 0; }
    public function __sleep(): array { return ['x']; }
}
$rt = phpser_unserialize(phpser_serialize(new BothEncode()));
echo ($rt->x === 100) ? "serialize_wins OK\n" : "serialize_wins FAIL ({$rt->x})\n";

// --- Class defines BOTH __unserialize and __wakeup: __unserialize wins
// (PHP doesn't call __wakeup when __unserialize exists). ---
class BothDecode {
    public int $x = 0;
    public bool $woke = false;
    public function __serialize(): array { return ['x' => 42]; }
    public function __unserialize(array $d): void { $this->x = $d['x']; }
    public function __wakeup(): void { $this->woke = true; }
}
$rt = phpser_unserialize(phpser_serialize(new BothDecode()));
echo ($rt->x === 42 && $rt->woke === false)
    ? "unserialize_no_wakeup OK\n" : "unserialize_no_wakeup FAIL (x={$rt->x} woke=" . var_export($rt->woke, true) . ")\n";

// --- __wakeup throws: exception propagates after __unserialize loop. ---
class WakeThrows {
    public function __wakeup(): void { throw new RuntimeException("wake boom"); }
}
$caught = false;
try {
    phpser_unserialize(phpser_serialize(new WakeThrows()));
} catch (RuntimeException $e) {
    $caught = ($e->getMessage() === "wake boom");
}
echo $caught ? "wakeup_throws OK\n" : "wakeup_throws FAIL\n";

?>
--EXPECT--
sleep_filter OK
sleep_visibility OK
sleep_unknown_skip OK
sleep_dynamic OK
sleep_uninit OK
sleep_throws OK
wakeup_called OK
wakeup_graph_complete OK
serialize_wins OK
unserialize_no_wakeup OK
wakeup_throws OK
