--TEST--
phpser: __serialize / __unserialize magic methods (PHP 7.4+) — incl. SPL classes
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70400) print "skip PHP 7.4+ required for __serialize";
?>
--FILE--
<?php

// --- Custom class with __serialize / __unserialize ---
class CustomMagic {
    private string $secret;
    public function __construct(string $s) { $this->secret = $s; }
    public function __serialize(): array { return ['s' => $this->secret, 'len' => strlen($this->secret)]; }
    public function __unserialize(array $data): void {
        $this->secret = $data['s'];
        if (strlen($this->secret) !== $data['len']) throw new RuntimeException("len mismatch");
    }
    public function getSecret(): string { return $this->secret; }
}
$c = new CustomMagic("hello world");
$rt = phpser_unserialize(phpser_serialize($c));
echo ($rt instanceof CustomMagic && $rt->getSecret() === "hello world") ? "custom OK\n" : "custom FAIL\n";

// --- SPL ArrayObject (the original bug054662 case) ---
$ao = new ArrayObject(['x' => 1, 'y' => 2]);
$ao->append("appended");
$rt = phpser_unserialize(phpser_serialize($ao));
echo ($rt instanceof ArrayObject) ? "arrayobject_class OK\n" : "arrayobject_class FAIL\n";
echo (serialize($ao) === serialize($rt)) ? "arrayobject_bytewise OK\n" : "arrayobject_bytewise FAIL\n";

// --- SplObjectStorage with attached objects ---
class Key { public int $id; public function __construct(int $i) { $this->id = $i; } }
$sos = new SplObjectStorage();
$k1 = new Key(1);
$k2 = new Key(2);
$sos->attach($k1, "value-1");
$sos->attach($k2, "value-2");
$rt = phpser_unserialize(phpser_serialize($sos));
echo ($rt instanceof SplObjectStorage && $rt->count() === 2) ? "spl_obj_storage OK\n" : "spl_obj_storage FAIL\n";

// --- DateTime — uses __serialize/__unserialize since PHP 7.4 ---
$dt = new DateTime("2026-05-20 12:00:00", new DateTimeZone("UTC"));
$rt = phpser_unserialize(phpser_serialize($dt));
echo ($rt instanceof DateTime && $rt->format("Y-m-d H:i:s") === "2026-05-20 12:00:00")
    ? "datetime OK\n" : "datetime FAIL\n";

// --- DateTimeImmutable ---
$dti = new DateTimeImmutable("2026-12-31 23:59:59", new DateTimeZone("UTC"));
$rt = phpser_unserialize(phpser_serialize($dti));
echo ($rt instanceof DateTimeImmutable && $rt->format("Y-m-d H:i:s") === "2026-12-31 23:59:59")
    ? "datetimeimmutable OK\n" : "datetimeimmutable FAIL\n";

// --- DateInterval ---
$di = new DateInterval("P1Y2M3DT4H5M6S");
$rt = phpser_unserialize(phpser_serialize($di));
echo ($rt instanceof DateInterval && $rt->y === 1 && $rt->h === 4) ? "dateinterval OK\n" : "dateinterval FAIL\n";

// --- SplDoublyLinkedList (push, pop, shift round-trip) ---
$dll = new SplDoublyLinkedList();
$dll->push("a"); $dll->push("b"); $dll->push("c");
$rt = phpser_unserialize(phpser_serialize($dll));
$out = [];
foreach ($rt as $v) $out[] = $v;
echo ($rt instanceof SplDoublyLinkedList && $out === ['a','b','c']) ? "spl_dll OK\n" : "spl_dll FAIL\n";

// --- Class with __serialize that THROWS — exception must propagate ---
class ThrowsOnSerialize {
    public function __serialize(): array { throw new RuntimeException("boom"); }
    public function __unserialize(array $d): void {}
}
$caught = false;
try {
    phpser_serialize(new ThrowsOnSerialize());
} catch (RuntimeException $e) {
    $caught = ($e->getMessage() === "boom");
}
echo $caught ? "throws_propagated OK\n" : "throws_propagated FAIL\n";

// --- Class with __serialize that returns a NESTED structure ---
class NestedMagic {
    public function __serialize(): array {
        return [
            'list' => [1, 2, 3],
            'inner' => new CustomMagic("nested"),  // recursion through phpser
            'map'   => ['a' => 1, 'b' => 2],
        ];
    }
    public function __unserialize(array $d): void {
        if (!is_array($d['list']) || !($d['inner'] instanceof CustomMagic)) {
            throw new RuntimeException("bad data");
        }
    }
}
$rt = phpser_unserialize(phpser_serialize(new NestedMagic()));
echo ($rt instanceof NestedMagic) ? "nested_magic OK\n" : "nested_magic FAIL\n";

// --- Array of magic objects ---
$arr = [new CustomMagic("a"), new CustomMagic("b"), new CustomMagic("c")];
$rt = phpser_unserialize(phpser_serialize($arr));
$ok = (count($rt) === 3
       && $rt[0]->getSecret() === "a"
       && $rt[1]->getSecret() === "b"
       && $rt[2]->getSecret() === "c");
echo $ok ? "arr_of_magic OK\n" : "arr_of_magic FAIL\n";
?>
--EXPECT--
custom OK
arrayobject_class OK
arrayobject_bytewise OK
spl_obj_storage OK
datetime OK
datetimeimmutable OK
dateinterval OK
spl_dll OK
throws_propagated OK
nested_magic OK
arr_of_magic OK
