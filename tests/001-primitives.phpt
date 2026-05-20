--TEST--
phpser: scalar round-trip (null, bool, int, float, string)
--SKIPIF--
<?php if (!extension_loaded("phpser")) print "skip phpser not loaded"; ?>
--FILE--
<?php
$cases = [
    null,
    true,
    false,
    0,
    1,
    -1,
    PHP_INT_MAX,
    PHP_INT_MIN,
    0.0,
    1.5,
    -3.14159,
    1e-300,
    1e300,
    "",
    "hello",
    "with \x00 null byte",
    "with \xff high byte",
    str_repeat("x", 100),
];
foreach ($cases as $i => $v) {
    $rt = phpser_unserialize(phpser_serialize($v));
    if ($v !== $rt) {
        printf("case %d MISMATCH: ", $i);
        var_dump($v, $rt);
    }
}
echo "OK\n";
?>
--EXPECT--
OK
