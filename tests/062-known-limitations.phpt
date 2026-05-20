--TEST--
phpser: documented limitations — features not yet supported, no-crash contract
--SKIPIF--
<?php if (!extension_loaded("phpser")) print "skip phpser not loaded"; ?>
--FILE--
<?php

// This file pins down behavior on shapes phpser DOESN'T fully support.
// Each case must (a) not crash, and (b) produce *something*. When we add
// proper support for one of these features the asserts below will need
// to be tightened — that's intentional. The file exists so an
// accidental regression that, say, segfaults on Serializable can't ship
// silently.

// --- ArrayObject (igbinary_bug54662 shape) ---
// SPL ArrayObject has a custom internal serializer. We don't honor
// ce->serialize, __serialize, or Serializable yet — so the internal
// storage doesn't round-trip. Contract for now: no crash, object
// instance comes back as the same class.
class StorageBug054662 { public $storage = "a string"; }
$collection = new ArrayObject();
$collection->append(new StorageBug054662());
$rt = phpser_unserialize(phpser_serialize($collection));
echo $rt instanceof ArrayObject ? "arrayobject_class OK\n" : "arrayobject_class FAIL\n";

// __serialize / __unserialize: SUPPORTED — see 063-magic-serialize.phpt.
// IS_REFERENCE sharing, object identity, and cycles: SUPPORTED —
// see 065-shared-refs.phpt.

// --- Closures and resources — emit NULL, no crash ---
$f = function () { return 42; };
$rt = phpser_unserialize(phpser_serialize($f));
echo $rt === null ? "closure_null OK\n" : "closure_null FAIL\n";

$r = fopen('php://memory', 'r');
$rt = phpser_unserialize(phpser_serialize($r));
echo $rt === null ? "resource_null OK\n" : "resource_null FAIL\n";
fclose($r);
?>
--EXPECT--
arrayobject_class OK
closure_null OK
resource_null OK
