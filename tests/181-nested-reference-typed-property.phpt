--TEST--
phpser: nested reference wrappers reject before typed-property assignment
--EXTENSIONS--
phpser
--FILE--
<?php
class T { public int $x = 0; }

foreach ([
    "\x02\x01\x01T\x11\x11\x12\x00\x01\x10\x00",
    "\x02\x02\x01T\x01x\x11\x11\x0a\x00\x01\x01\x10\x00",
    "\x02\x00\x11\x11\x03\x02",
    "\x02\x00\x11\x10\x00",
] as $frame) {
    var_dump(phpser_unserialize($frame));
    try {
        phpser_unserialize_signed($frame . hash_hmac('sha256', $frame, 'k', true), 'k');
        echo "signed accepted\n";
    } catch (Exception $e) {
        echo str_contains($e->getMessage(), 'failed to decode') ? "signed rejected\n" : "wrong failure\n";
    }
}

$object = new T;
$value = [&$object->x, $object];
$copy = phpser_unserialize(phpser_serialize($value));
$copy[0] = 42;
var_dump($copy[1]->x);
$cycle = [];
$cycle[0] = &$cycle;
$copy = phpser_unserialize(phpser_serialize($cycle));
$copy[0]['x'] = 7;
var_dump($copy[0][0]['x']);
?>
--EXPECT--
NULL
signed rejected
NULL
signed rejected
NULL
signed rejected
NULL
signed rejected
int(42)
int(7)
