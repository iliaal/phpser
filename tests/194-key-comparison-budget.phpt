--TEST--
phpser: repeated long colliding dictionary keys consume a compared-byte budget
--EXTENSIONS--
phpser
--FILE--
<?php
function vi($n) {
    $s = '';
    while ($n >= 128) { $s .= chr(($n & 127) | 128); $n >>= 7; }
    return $s . chr($n);
}
function keys($count) {
    $dict = '';
    for ($i = 0; $i < $count; $i++) {
        $key = str_repeat('a', 4096);
        for ($bit = 0; $bit < 6; $bit++) { $key .= ($i & (1 << $bit)) ? 'FY' : 'Ez'; }
        $dict .= vi(strlen($key)) . $key;
    }
    return $dict;
}
function schema($count, $repeat) {
    $row = "\x13" . vi($count);
    for ($i = 0; $i < $count; $i++) { $row .= vi($i); }
    $row .= str_repeat("\x00", $count);
    return "\x02" . vi($count) . keys($count)
        . "\x07" . vi($repeat) . str_repeat($row, $repeat);
}
foreach ([2, 33] as $count) {
    var_dump(phpser_unserialize(schema($count, 1000)) === null);
    $ok = phpser_unserialize(schema($count, 1));
    var_dump(count($ok[0]) === $count);
}
$dict = "\x02\x02" . keys(2);
$body = "\x06" . vi(10002) . "\x01\x00\x00\x01\x01\x00"
    . str_repeat("\x01\x00\x00", 10000);
var_dump(phpser_unserialize($dict . $body) === null);
$objectDict = "\x02\x03" . keys(2) . "\x08stdClass";
$objects = "\x0a\x02" . vi(10002) . "\x00\x00\x01\x00"
    . str_repeat("\x00\x00", 10000);
var_dump(phpser_unserialize($objectDict . $objects) === null);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
