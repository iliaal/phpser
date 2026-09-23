--TEST--
phpser: documented limitations: features not yet supported, no-crash contract
--EXTENSIONS--
phpser
--FILE--
<?php


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


// --- Closures and resources emit NULL, no crash ---
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
