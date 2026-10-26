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


// --- Closures throw; resource encoding remains unchanged ---
$f = function () { return 42; };
try {
    phpser_serialize($f);
    echo "closure_throw FAIL\n";
} catch (Exception $e) {
    echo $e->getMessage() === "Serialization of 'Closure' is not allowed"
        ? "closure_throw OK\n" : "closure_throw FAIL\n";
}

$r = fopen('php://memory', 'r');
$rt = phpser_unserialize(phpser_serialize($r));
echo $rt === null ? "resource_null OK\n" : "resource_null FAIL\n";
fclose($r);
?>
--EXPECT--
arrayobject_state OK
closure_throw OK
resource_null OK
