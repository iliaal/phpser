--TEST--
phpser: __unserialize/__wakeup precedence follows the class definition, not the wire tag
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70400) print "skip PHP 7.4+ required for __serialize/__unserialize";
?>
--FILE--
<?php
// Native unserialize() picks the rebuild path from the *current* class: if the
// class defines __unserialize(), the decoded pairs are handed to it and
// __wakeup() never runs; otherwise properties are installed and __wakeup()
// fires if present. phpser encodes by serialize-precedence (a __serialize
// class -> magic tag, everything else -> plain object tag), so the decoder must
// replay that decision from the class, independent of which tag carried it.

// Case A: __unserialize() but no __serialize()/__sleep() -> encoded as a plain
// object tag, but native still routes through __unserialize().
class OnlyUnser {
    public int $raw = 0;
    public int $rebuilt = 0;
    public function __unserialize(array $d): void {
        $this->raw = $d["raw"] ?? -1;
        $this->rebuilt = 1; // invariant-rebuild marker
    }
}
$a = new OnlyUnser(); $a->raw = 7;
$nat = unserialize(serialize($a));
$ph  = phpser_unserialize(phpser_serialize($a));
echo ($nat->raw === $ph->raw && $nat->rebuilt === $ph->rebuilt && $ph->rebuilt === 1)
    ? "A __unserialize fired OK\n" : "A FAIL ({$ph->raw}/{$ph->rebuilt})\n";

// Case B: __serialize() + __wakeup() but no __unserialize() -> encoded as the
// magic tag; the no-__unserialize fallback must still fire __wakeup().
class SerWake {
    public int $v = 0;
    public int $woke = 0;
    public function __serialize(): array { return ["v" => $this->v]; }
    public function __wakeup(): void { $this->woke = 1; }
}
$b = new SerWake(); $b->v = 9;
$nat = unserialize(serialize($b));
$ph  = phpser_unserialize(phpser_serialize($b));
echo ($nat->v === $ph->v && $nat->woke === $ph->woke && $ph->woke === 1)
    ? "B __wakeup fired OK\n" : "B FAIL ({$ph->v}/{$ph->woke})\n";

// Case C: both __unserialize() and __wakeup() -> __unserialize() wins,
// __wakeup() must NOT fire.
class Both {
    public int $v = 0; public int $woke = 0; public int $unser = 0;
    public function __serialize(): array { return ["v" => $this->v]; }
    public function __unserialize(array $d): void { $this->v = $d["v"]; $this->unser = 1; }
    public function __wakeup(): void { $this->woke = 1; }
}
$c = new Both(); $c->v = 3;
$nat = unserialize(serialize($c));
$ph  = phpser_unserialize(phpser_serialize($c));
echo ($ph->unser === 1 && $ph->woke === 0 && $nat->unser === $ph->unser && $nat->woke === $ph->woke)
    ? "C __unserialize wins OK\n" : "C FAIL ({$ph->unser}/{$ph->woke})\n";

// Case D: plain class with neither magic method -> properties installed, no hook.
class Plain { public int $x = 0; }
$d = new Plain(); $d->x = 5;
$ph = phpser_unserialize(phpser_serialize($d));
echo ($ph instanceof Plain && $ph->x === 5) ? "D plain props OK\n" : "D FAIL\n";

// Case E: private property survives a __unserialize-only round-trip (the data
// array keys are the mangled names, matching native).
class PrivUnser {
    private string $secret = "";
    public function __unserialize(array $d): void {
        // Native feeds mangled keys; read by reconstructing the mangled name.
        $this->secret = $d["\0PrivUnser\0secret"] ?? "MISSING";
    }
    public function get(): string { return $this->secret; }
}
$e = new PrivUnser();
(function () { $this->secret = "hidden"; })->call($e);
$nat = unserialize(serialize($e));
$ph  = phpser_unserialize(phpser_serialize($e));
echo ($nat->get() === $ph->get() && $ph->get() === "hidden")
    ? "E mangled-key passthrough OK\n" : "E FAIL ({$ph->get()})\n";
?>
--EXPECT--
A __unserialize fired OK
B __wakeup fired OK
C __unserialize wins OK
D plain props OK
E mangled-key passthrough OK
