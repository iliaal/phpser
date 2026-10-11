--TEST--
phpser: shared magic data copies consume a cumulative allocation budget
--EXTENSIONS--
phpser
--FILE--
<?php
function vi($n) {
    $s = '';
    while ($n >= 128) { $s .= chr(($n & 127) | 128); $n >>= 7; }
    return $s . chr($n);
}
function frame($n, $copies, $affine) {
    $data = $affine ? "\x17" . vi($n) . "\x00\x00"
        : "\x08" . vi($n) . str_repeat("\x00", $n);
    return "\x02\x01\x08stdClass\x07" . vi($copies + 1) . "\x18" . $data
        . str_repeat("\x0e\x00\x10\x00", $copies);
}
foreach ([true, false] as $allow) {
    foreach ([true, false] as $affine) {
        var_dump(phpser_unserialize(frame(65536, 5, $affine),
            ['allowed_classes' => $allow]) === null);
        $ok = phpser_unserialize(frame(256, 3, $affine),
            ['allowed_classes' => $allow]);
        var_dump(count($ok) === 4, ((array)$ok[3])[255] === 0);
    }
}
var_dump(phpser_unserialize(frame(32768, 5, true), ['allowed_classes' => false]) === null);
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
