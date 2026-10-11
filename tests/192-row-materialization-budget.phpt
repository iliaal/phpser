--TEST--
phpser: row allocations are budgeted and large encoder inputs use a round-trip-safe fallback
--EXTENSIONS--
phpser
--FILE--
<?php
function vi($n) {
    $s = '';
    while ($n >= 128) { $s .= chr(($n & 127) | 128); $n >>= 7; }
    return $s . chr($n);
}
function rows($tag, $n) {
    return $tag . vi($n) . "\x01\x00"
        . ($tag === "\x15" ? "\x07" : '') . str_repeat("\x00", $n);
}
foreach (["\x14", "\x15"] as $tag) {
    $header = "\x02\x01\x01a";
    var_dump(phpser_unserialize($header . rows($tag, 65536)) === null);
    var_dump(phpser_unserialize($header . "\x07\x02"
        . rows($tag, 40000) . rows($tag, 40000)) === null);
    $ok = phpser_unserialize($header . rows($tag, 8192));
    var_dump(count($ok) === 8192, $ok[8191] === ['a' => null]);
}
foreach ([false, true] as $vary) {
    $input = [];
    for ($i = 0; $i < 50000; $i++) { $input[] = ['a' => $vary ? $i : 0]; }
    var_dump(phpser_unserialize(phpser_serialize($input)) === $input);
}
$input = [];
for ($i = 0; $i < 50000; $i++) {
    $input[] = ['a' => $i, 'b' => 0, 'c' => 0, 'd' => 0,
        'e' => 0, 'f' => 0, 'g' => 0, 'h' => 0];
}
$wire = phpser_serialize($input);
var_dump(phpser_unserialize($wire) === $input);
var_dump(strlen($wire) < 9 * count($input));
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
