--TEST--
phpser: numeric-string keys use ordinary arrays instead of schema tags
--EXTENSIONS--
phpser
--FILE--
<?php
function numericRow(string $key, int $value): array {
    ${$key} = $value;
    $name = 'name-' . $value;
    return compact($key, 'name');
}
function bodyTag(string $frame): int {
    $offset = 1;
    $read = function () use ($frame, &$offset): int {
        $value = 0;
        $shift = 0;
        do {
            $byte = ord($frame[$offset++]);
            $value |= ($byte & 127) << $shift;
            $shift += 7;
        } while ($byte & 128);
        return $value;
    };
    $count = $read();
    for ($i = 0; $i < $count; $i++) {
        $length = $read();
        $offset += $length;
    }
    return ord($frame[$offset]);
}
foreach (['0', '1', '-1', (string) PHP_INT_MAX, (string) PHP_INT_MIN] as $key) {
    $rows = [];
    for ($i = 0; $i < 6; $i++) $rows[] = numericRow($key, $i);
    $expected = unserialize(serialize($rows));
    $frame = phpser_serialize($rows);
    var_dump(bodyTag($frame) !== 0x15);
    var_dump(phpser_unserialize($frame) === $expected);
    var_dump(phpser_unserialize_signed(phpser_serialize_signed($rows, 'numeric-keys'), 'numeric-keys') === $expected);

    $assoc = array_combine(['a', 'b', 'c', 'd', 'e', 'f'], $rows);
    var_dump(phpser_unserialize(phpser_serialize($assoc)) === unserialize(serialize($assoc)));
}
foreach (['01', '+1', '-0', 'field'] as $key) {
    $rows = [numericRow($key, 1), numericRow($key, 2)];
    $frame = phpser_serialize($rows);
    var_dump(bodyTag($frame) === 0x15);
    var_dump(phpser_unserialize($frame) === $rows);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
