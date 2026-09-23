--TEST--
phpser: class-typed slots always verify (wrong-class object rejected)
--EXTENSIONS--
phpser
--FILE--
<?php
class CR1A {}
class CR1B { public CR1A $slot; }
class CR1X {}

$b = new CR1B();
$b->slot = new CR1A();
$rt = phpser_unserialize(phpser_serialize($b));
echo ($rt instanceof CR1B && $rt->slot instanceof CR1A) ? "valid OK\n" : "valid FAIL\n";

// A same-length dictionary-name swap puts the wrong class in a typed slot.
$bad = str_replace("CR1A", "CR1X", phpser_serialize($b));
try {
    $r = phpser_unserialize($bad);
    echo "smuggle FAIL (accepted " . get_class($r->slot) . ")\n";
} catch (TypeError $e) {
    echo "smuggle OK\n";
}

$bad2 = str_replace("CR1A", "CR1X", phpser_serialize($b));
try {
    phpser_unserialize($bad2, ["allowed_classes" => true]);
    echo "smuggle2 FAIL\n";
} catch (TypeError $e) {
    echo "smuggle2 OK\n";
}
?>
--EXPECT--
valid OK
smuggle OK
smuggle2 OK
