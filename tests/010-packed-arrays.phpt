--TEST--
phpser: packed arrays (longs, doubles, strings, mixed)
--SKIPIF--
<?php if (!extension_loaded("phpser")) print "skip phpser not loaded"; ?>
--FILE--
<?php
$cases = [
    "empty"   => [],
    "longs"   => [1, 2, 3, 4, 5],
    "doubles" => [1.5, 2.5, 3.5],
    "strings" => ["a", "b", "c"],
    "mixed"   => [1, "two", 3.0, true, null],
    "longs_big"   => range(0, 999),
    "doubles_big" => array_map(fn($i) => $i * 0.5, range(0, 99)),
];
foreach ($cases as $name => $v) {
    $rt = phpser_unserialize(phpser_serialize($v));
    if (serialize($rt) !== serialize($v)) {
        echo "MISMATCH $name\n";
        var_dump($v, $rt);
    }
}
echo "OK\n";
?>
--EXPECT--
OK
