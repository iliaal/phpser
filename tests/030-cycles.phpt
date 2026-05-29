--TEST--
phpser: cycle safety (depth cap prevents crash, IS_REFERENCE flattens)
--EXTENSIONS--
phpser
--FILE--
<?php
// Self-referential array — would loop forever via the flatten path
// without MAX_DEPTH. The result is truncated but the call returns.
$a = ["start"];
$a[] = &$a;
$bytes = phpser_serialize($a);
echo "self_array survived: ", strlen($bytes) > 0 ? "yes" : "no", "\n";
$rt = phpser_unserialize($bytes);
echo "decoded type: ", gettype($rt), "\n";

// Object self-reference
$o = new stdClass();
$o->self = &$o;
$bytes = phpser_serialize($o);
echo "self_obj survived: ", strlen($bytes) > 0 ? "yes" : "no", "\n";
$rt = phpser_unserialize($bytes);
echo "decoded type: ", gettype($rt), "\n";

// Deep but non-cyclic — well under MAX_DEPTH=512
$cur = ["leaf" => 42];
for ($i = 0; $i < 100; $i++) {
    $cur = ["next" => $cur];
}
$rt = phpser_unserialize(phpser_serialize($cur));
echo "deep_100 round-trip: ", serialize($rt) === serialize($cur) ? "OK" : "FAIL", "\n";
?>
--EXPECT--
self_array survived: yes
decoded type: array
self_obj survived: yes
decoded type: object
deep_100 round-trip: OK
