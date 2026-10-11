--TEST--
phpser: integer keys sharing low hash bits round-trip while excessive walks remain bounded
--EXTENSIONS--
phpser
--FILE--
<?php
foreach ([64, 65, 1000] as $n) {
    $input = [];
    for ($i = 0; $i < $n; $i++) { $input[$i * 256] = $i; }
    var_dump(phpser_unserialize(phpser_serialize($input)) === $input);
    $key = 'bucket-sharing';
    var_dump(phpser_unserialize_signed(phpser_serialize_signed($input, $key), $key) === $input);
}
function vi($n) {
    $s = '';
    while ($n >= 128) { $s .= chr(($n & 127) | 128); $n >>= 7; }
    return $s . chr($n);
}
$frame = "\x01\x00\x06" . vi(10000);
for ($i = 0; $i < 10000; $i++) { $frame .= "\x00" . vi($i * 32768) . "\x00"; }
var_dump(phpser_unserialize($frame) === null);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
