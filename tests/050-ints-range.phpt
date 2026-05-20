--TEST--
phpser: integer round-trip across the full range (ext/standard 001, igbinary_004, igbinary_068)
--EXTENSIONS--
phpser
--FILE--
<?php
$cases = [
    0, 1, -1,
    127, -128,                          // varint 1-byte boundary
    128, -129,                          // varint 2-byte boundary
    16383, -16384,                      // varint 2/3-byte boundary
    16384, -16385,                      // varint 3-byte boundary
    2097151, -2097152,                  // varint 3/4-byte boundary
    1000, -1000,
    100000, -100000,
    1234567890, -1234567890,
    2147483647, -2147483648,            // i32 boundary
    2147483648, -2147483649,            // beyond i32
    PHP_INT_MAX, PHP_INT_MIN,
    PHP_INT_MAX - 1, PHP_INT_MIN + 1,
];
$fail = 0;
foreach ($cases as $i => $v) {
    $rt = phpser_unserialize(phpser_serialize($v));
    if ($v !== $rt) { printf("MISMATCH idx=%d v=%d rt=%d\n", $i, $v, $rt); $fail++; }
}
echo $fail ? "FAILED $fail\n" : "OK\n";
?>
--EXPECT--
OK
