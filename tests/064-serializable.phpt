--TEST--
phpser: legacy Serializable interface (ce->serialize / ce->unserialize hooks)
--EXTENSIONS--
phpser
--FILE--
<?php

// A class that implements only the legacy Serializable interface (no
// __serialize). On PHP 8.1+ this triggers a deprecation but still works;
// we suppress for the duration of the test since we're exercising the
// path, not the deprecation message.
@error_reporting(E_ALL & ~E_DEPRECATED);

class LegacySer implements Serializable {
    private string $data;
    public function __construct(string $d = '') { $this->data = $d; }
    public function serialize(): string { return "v1|" . $this->data; }
    public function unserialize(string $payload): void {
        if (!str_starts_with($payload, "v1|")) throw new RuntimeException("bad version");
        $this->data = substr($payload, 3);
    }
    public function get(): string { return $this->data; }
}

$cases = [
    'simple'   => new LegacySer("hello"),
    'empty'    => new LegacySer(""),
    'binary'   => new LegacySer("with\x00null"),
    'long'     => new LegacySer(str_repeat("x", 1000)),
];

$fail = 0;
foreach ($cases as $name => $v) {
    $rt = phpser_unserialize(phpser_serialize($v));
    if (!($rt instanceof LegacySer) || $rt->get() !== $v->get()) {
        echo "MISMATCH $name (got=" . var_export($rt, true) . ")\n";
        $fail++;
    }
}
echo $fail ? "FAILED $fail\n" : "user_legacy OK\n";

// Array containing multiple legacy-serializable instances
$arr = [new LegacySer("a"), new LegacySer("b"), new LegacySer("c")];
$rt = phpser_unserialize(phpser_serialize($arr));
$ok = count($rt) === 3
    && $rt[0]->get() === "a"
    && $rt[1]->get() === "b"
    && $rt[2]->get() === "c";
echo $ok ? "array_of_legacy OK\n" : "array_of_legacy FAIL\n";

// A subclass of a Serializable class — should still round-trip via the
// inherited ce->serialize hook.
class LegacySerSub extends LegacySer {
    public function tag(): string { return "sub:" . $this->get(); }
}
$rt = phpser_unserialize(phpser_serialize(new LegacySerSub("hi")));
echo ($rt instanceof LegacySerSub && $rt->tag() === "sub:hi") ? "subclass OK\n" : "subclass FAIL\n";

// Throwing from serialize() — PHP wraps the throw; phpser should
// propagate it via the exception PHP raises into the calling scope.
class ThrowsOnSer implements Serializable {
    public function serialize(): string { throw new RuntimeException("serialize boom"); }
    public function unserialize(string $d): void {}
}
$caught = false;
try {
    phpser_serialize(new ThrowsOnSer());
} catch (RuntimeException $e) {
    $caught = ($e->getMessage() === "serialize boom");
}
echo $caught ? "throws_propagated OK\n" : "throws_propagated FAIL\n";

// Throwing from the legacy unserialize hook must propagate too. Mutate only
// the opaque legacy payload, keeping the frame structurally valid so this
// reaches LegacySer::unserialize() rather than failing at header parsing.
$bad = phpser_serialize(new LegacySer("payload"));
$at = strpos($bad, "v1|payload");
$bad[$at] = "x";
$caught = false;
try {
    phpser_unserialize($bad);
} catch (RuntimeException $e) {
    $caught = ($e->getMessage() === "bad version");
}
echo $caught ? "unserialize_throws OK\n" : "unserialize_throws FAIL\n";

// SplPriorityQueue / SplMinHeap — PHP's own serialize() returns empty
// O:N:"Class":0:{} for these (their internal heap data isn't accessible
// to a serializer). We expect to match that behavior — class survives,
// contents lost.
$pq = new SplPriorityQueue();
$pq->insert("a", 1);
$pq->insert("b", 2);
$rt = phpser_unserialize(phpser_serialize($pq));
echo ($rt instanceof SplPriorityQueue) ? "splpq_class OK\n" : "splpq_class FAIL\n";

class MyHeap extends SplMinHeap {}
$h = new MyHeap();
$h->insert(3);
$h->insert(1);
$rt = phpser_unserialize(phpser_serialize($h));
echo ($rt instanceof MyHeap) ? "splheap_class OK\n" : "splheap_class FAIL\n";
?>
--EXPECT--
user_legacy OK
array_of_legacy OK
subclass OK
throws_propagated OK
unserialize_throws OK
splpq_class OK
splheap_class OK
