--TEST--
phpser: CR-001 class-typed slots always verify (wrong-class object rejected)
--EXTENSIONS--
phpser
--FILE--
<?php
class CR1A {}
class CR1B { public CR1A $slot; }
class CR1X {}

// Valid round-trip still works (fast path preserved for correct types).
$b = new CR1B();
$b->slot = new CR1A();
$rt = phpser_unserialize(phpser_serialize($b));
echo ($rt instanceof CR1B && $rt->slot instanceof CR1A) ? "valid OK\n" : "valid FAIL\n";

// Smuggle a wrong-class object into the class-typed slot via a same-length
// dict-name swap. The old mask-only fast path accepted any IS_OBJECT here;
// the fix forces class-constrained slots through the engine verifier.
$bad = str_replace("CR1A", "CR1X", phpser_serialize($b));
try {
    $r = phpser_unserialize($bad);
    echo "smuggle FAIL (accepted " . get_class($r->slot) . ")\n";
} catch (TypeError $e) {
    echo "smuggle OK\n";
}

// Scalar into the class slot still fails too (mask miss -> verifier).
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
