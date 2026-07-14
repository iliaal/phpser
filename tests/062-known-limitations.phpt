--TEST--
phpser: documented limitations — features not yet supported, no-crash contract
--EXTENSIONS--
phpser
--FILE--
<?php

// This file pins down behavior on shapes phpser DOESN'T fully support.
// Each case must (a) not crash, and (b) produce *something*. When we add
// proper support for one of these features the asserts below will need
// to be tightened — that's intentional. The file exists so an
// accidental regression that, say, segfaults on Serializable can't ship
// silently.

// --- ArrayObject (igbinary_bug54662 shape) ---
// Internal __serialize support must preserve both the container and its stored
// object; checking only the class would let silent state loss pass.
class StorageBug054662 { public $storage = "a string"; }
$collection = new ArrayObject();
$collection->append(new StorageBug054662());
$rt = phpser_unserialize(phpser_serialize($collection));
$item = $rt instanceof ArrayObject ? $rt[0] ?? null : null;
echo $rt instanceof ArrayObject && count($rt) === 1
    && $item instanceof StorageBug054662 && $item->storage === "a string"
    ? "arrayobject_state OK\n" : "arrayobject_state FAIL\n";

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
arrayobject_state OK
closure_null OK
resource_null OK
