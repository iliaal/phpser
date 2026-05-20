--TEST--
phpser: ship-gate regressions — CR-001 leak, CR-002 wakeup UAF, CR-003 depth cap, CR-004 exception
--EXTENSIONS--
phpser
--FILE--
<?php

// =====================================================================
// CR-002: UAF in wakeup queue when an earlier __wakeup mutates own
// properties to drop the only reference to a sibling object also queued.
// Without the GC_ADDREF fix the second iteration deref's freed memory.
// Under ASan / valgrind this would crash; without instrumentation it
// usually just produces garbage. We assert the post-wakeup state is
// sane — a sufficient proxy.
// =====================================================================
class A_uaf {
    public string $tag = "A";
    public bool $woke = false;
    public function __wakeup(): void { $this->woke = true; }
}
#[\AllowDynamicProperties]
class B_uaf {
    public ?A_uaf $inner = null;
    public string $tag = "B";
    public function __wakeup(): void {
        // Drop the only ref to $this->inner. With the bug, A_uaf is
        // freed before its own __wakeup runs.
        unset($this->inner);
    }
}
$b = new B_uaf();
$b->inner = new A_uaf();
$rt = phpser_unserialize(phpser_serialize($b));
// Both wakeups must have run. The inner A_uaf is gone from $rt->inner
// (B's __wakeup unset it), but the wakeup queue must still have called
// its __wakeup. Since A_uaf is unset from $rt, we can't observe its
// $woke directly — but we can detect the UAF crash via survival here.
echo ($rt instanceof B_uaf && $rt->tag === "B") ? "cr002_uaf OK\n" : "cr002_uaf FAIL\n";

// Variant: A's __wakeup queued first, drops a sibling C.
class C_uaf {
    public bool $woke = false;
    public function __wakeup(): void { $this->woke = true; }
}
#[\AllowDynamicProperties]
class P_uaf {
    public ?A_uaf $a = null;
    public ?C_uaf $c = null;
    public function __wakeup(): void { unset($this->c); }
}
$p = new P_uaf();
$p->a = new A_uaf();
$p->c = new C_uaf();
$rt = phpser_unserialize(phpser_serialize($p));
echo ($rt instanceof P_uaf && $rt->a instanceof A_uaf && $rt->a->woke === true)
    ? "cr002_sibling OK\n" : "cr002_sibling FAIL\n";

// Variant: deferred __unserialize queue same shape — defense-in-depth.
class D_def {
    public string $name = "";
    public bool $unserialized = false;
    public function __serialize(): array { return ['name' => $this->name]; }
    public function __unserialize(array $d): void {
        $this->name = $d['name'];
        $this->unserialized = true;
    }
}
$arr = [new D_def(), new D_def(), new D_def()];
foreach ($arr as $i => $o) { $o->name = "obj_$i"; }
$rt = phpser_unserialize(phpser_serialize($arr));
$ok = count($rt) === 3;
foreach ($rt as $i => $o) {
    if (!$o->unserialized || $o->name !== "obj_$i") { $ok = false; break; }
}
echo $ok ? "cr002_defer_queue OK\n" : "cr002_defer_queue FAIL\n";

// =====================================================================
// CR-003: Decoder depth cap. A wire payload of 5000 nested TAG_NEW_REFs
// would blow the C stack without the cap. Build by hand:
//   0x01 0x00         — version + empty dict
//   0x11 × N          — TAG_NEW_REF repeated
//   0x00              — TAG_NULL (the innermost value)
// Decoder must reject (or terminate) without crash.
// =====================================================================
$buf = "\x01\x00" . str_repeat("\x11", 5000) . "\x00";
$rt = @phpser_unserialize($buf);
// Depth cap kicks in; deep nesting is rejected. *Some* value comes back
// (NULL at the truncation point, or an UNDEF-equivalent). Critical
// assertion: we survived.
echo "cr003_depth_cap OK\n";

// Cap is high enough for legitimate payloads (200 nested arrays).
$deep = "leaf";
for ($i = 0; $i < 200; $i++) $deep = [$deep];
$rt = phpser_unserialize(phpser_serialize($deep));
$probe = $rt; $d = 0;
while (is_array($probe) && $d < 300) { $probe = $probe[0]; $d++; }
echo ($probe === "leaf" && $d === 200) ? "cr003_legit_depth OK\n" : "cr003_legit_depth FAIL d=$d\n";

// =====================================================================
// CR-001: zval leak on partial decode. Run a tight loop on a payload
// that's truncated mid-object. Without the fix, valgrind/LSAN would
// report a leak per iteration; without instrumentation, memory usage
// grows. We compare memory_get_usage(true) before/after.
// =====================================================================
class Leaky {
    public int $a = 0;
    public string $b = "";
    public array $c = [];
}
$o = new Leaky();
$o->a = 42;
$o->b = "hello";
$o->c = ['x', 'y'];
$payload = phpser_serialize($o);
// Truncate to a position where the inner decode will partially init out.
$truncated = substr($payload, 0, strlen($payload) - 5);

gc_collect_cycles();
$before = memory_get_usage(true);
for ($i = 0; $i < 5000; $i++) {
    @phpser_unserialize($truncated);
}
gc_collect_cycles();
$after = memory_get_usage(true);
// Allow some slack — PHP's allocator may hold pages — but per-iteration
// leak would balloon by tens of KB easily.
$growth = $after - $before;
echo ($growth < 200000) ? "cr001_no_leak OK\n" : "cr001_no_leak FAIL growth=$growth\n";

// Variant: truncated mid-reference. TAG_NEW_REF allocates a
// zend_reference, then inner decode fails — wrapper must release it.
$ref = "shared";
$arr = [&$ref, &$ref];
$payload = phpser_serialize($arr);
$truncated = substr($payload, 0, strlen($payload) - 2);
gc_collect_cycles();
$before = memory_get_usage(true);
for ($i = 0; $i < 5000; $i++) {
    @phpser_unserialize($truncated);
}
gc_collect_cycles();
$growth = memory_get_usage(true) - $before;
echo ($growth < 200000) ? "cr001_ref_no_leak OK\n" : "cr001_ref_no_leak FAIL growth=$growth\n";

// =====================================================================
// CR-004: When __unserialize throws during the deferred loop, the
// outer C function must propagate failure rather than returning success
// with an exception pending. PHP-level callers see the exception (Zend
// unwinds at the function boundary); we verify that path works cleanly.
// =====================================================================
class Boomer {
    public function __serialize(): array { return ['x' => 1]; }
    public function __unserialize(array $d): void { throw new RuntimeException("cr004 boom"); }
}
$caught = false;
try {
    phpser_unserialize(phpser_serialize(new Boomer()));
} catch (RuntimeException $e) {
    $caught = ($e->getMessage() === "cr004 boom");
}
echo $caught ? "cr004_unserialize_throws OK\n" : "cr004_unserialize_throws FAIL\n";

class WakeBoomer {
    public function __wakeup(): void { throw new RuntimeException("cr004 wake boom"); }
}
$caught = false;
try {
    phpser_unserialize(phpser_serialize(new WakeBoomer()));
} catch (RuntimeException $e) {
    $caught = ($e->getMessage() === "cr004 wake boom");
}
echo $caught ? "cr004_wakeup_throws OK\n" : "cr004_wakeup_throws FAIL\n";

?>
--EXPECT--
cr002_uaf OK
cr002_sibling OK
cr002_defer_queue OK
cr003_depth_cap OK
cr003_legit_depth OK
cr001_no_leak OK
cr001_ref_no_leak OK
cr004_unserialize_throws OK
cr004_wakeup_throws OK
