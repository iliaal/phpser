--TEST--
phpser: associative arrays (string keys, mixed keys, sparse, post-unset)
--SKIPIF--
<?php if (!extension_loaded("phpser")) print "skip phpser not loaded"; ?>
--FILE--
<?php
$cases = [
    "assoc"      => ["a" => 1, "b" => "two", "c" => 3.0],
    "nested"     => ["outer" => ["inner" => [1, 2, 3], "k" => "v"]],
    "mixed_keys" => ["a" => 1, 5 => 2, "b" => 3],
    "neg_keys"   => [-1 => "a", 0 => "b", 1 => "c"],
    "sparse"     => [0 => "a", 1 => "b", 5 => "c"],
    "post_unset" => (function () {
        $a = [1, 2, 3, 4, 5];
        unset($a[2]);
        return $a;
    })(),
    "rowset"     => array_map(
        fn($i) => ["id" => $i, "name" => "row_$i", "tags" => ["a", "b"]],
        range(1, 10)
    ),
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
