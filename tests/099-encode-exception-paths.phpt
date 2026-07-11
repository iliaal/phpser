--TEST--
phpser: encode aborts (no partial frame) when a serialization hook throws
--DESCRIPTION--
Covers the e->failed / id-rollback branches that abort the encode walk when
user code throws mid-serialize: the lazy-object initializer (triggered by
zend_get_properties_for on the object slow path) and __serialize. A throwing
hook must propagate the exception and never hand back a truncated frame with a
TAG_NULL hole (which the session encoder would otherwise persist).
--EXTENSIONS--
phpser
--FILE--
<?php

// 1. A lazy ghost whose initializer throws during serialize (CR-003). The
//    zend_get_properties_for call on the object slow path runs the initializer;
//    the new EG(exception) guard must roll back the claimed id, flag failure,
//    and let the exception propagate — no partial payload returned.
class LazyDto { public int $x = 0; public string $y = ""; }
$r = new ReflectionClass(LazyDto::class);
$ghost = $r->newLazyGhost(function ($o) { throw new RuntimeException("init boom"); });
try {
    phpser_serialize($ghost);
    echo "lazy_init_throw FAIL (no exception, partial frame returned)\n";
} catch (RuntimeException $e) {
    echo ($e->getMessage() === "init boom") ? "lazy_init_throw OK\n" : "lazy_init_throw FAIL msg\n";
}

// 2. The same lazy object nested inside a larger graph must abort the whole
//    frame, not ship a hole where the object was.
$graph = ['before' => 1, 'obj' => $ghost, 'after' => 2];
try {
    phpser_serialize($graph);
    echo "lazy_nested_throw FAIL (no exception)\n";
} catch (RuntimeException $e) {
    echo "lazy_nested_throw OK\n";
}

// 3. __serialize that throws aborts likewise (existing e->failed path, kept as a
//    sibling assertion so the whole abort family is pinned together).
class Boom {
    public function __serialize(): array { throw new LogicException("ser boom"); }
}
try {
    phpser_serialize(new Boom());
    echo "serialize_throw FAIL (no exception)\n";
} catch (LogicException $e) {
    echo ($e->getMessage() === "ser boom") ? "serialize_throw OK\n" : "serialize_throw FAIL msg\n";
}

// 4. Process integrity: after all the aborted encodes, a normal serialize still
//    works (no leaked encode state / dangling id table).
$ok = phpser_unserialize(phpser_serialize(['sane' => [1, 2, 3]]));
echo ($ok === ['sane' => [1, 2, 3]]) ? "recovery OK\n" : "recovery FAIL\n";

?>
--EXPECT--
lazy_init_throw OK
lazy_nested_throw OK
serialize_throw OK
recovery OK
