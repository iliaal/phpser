--TEST--
phpser: dictionary collisions fall back without losing keys or values
--EXTENSIONS--
phpser
--FILE--
<?php
function collisionKey(int $i): string {
    $key = '';
    for ($bit = 0; $bit < 9; $bit++) {
        $key .= ($i >> $bit) & 1 ? 'FY' : 'Ez';
    }
    return $key;
}
function dictCount(string $frame): int {
    $count = 0;
    $shift = 0;
    for ($i = 1; ; $i++) {
        $byte = ord($frame[$i]);
        $count |= ($byte & 127) << $shift;
        if ($byte < 128) return $count;
        $shift += 7;
    }
}
$objects = [];
for ($repeat = 0; $repeat < 2; $repeat++) {
    for ($i = 0; $i < 512; $i++) {
        $object = new stdClass();
        $object->{collisionKey($i)} = $i;
        $objects[] = $object;
    }
}
$frame = phpser_serialize($objects);
var_dump(phpser_unserialize($frame) == $objects);
// A bounded content map may emit repeated dictionary entries after fallback.
var_dump(dictCount($frame) > 513);
$signed = phpser_serialize_signed($objects, 'dictionary-collisions');
var_dump(phpser_unserialize_signed($signed, 'dictionary-collisions') == $objects);

$same = $objects[0];
$objects[] = $same;
$decoded = phpser_unserialize(phpser_serialize($objects));
var_dump($decoded[0] === $decoded[count($decoded) - 1]);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
