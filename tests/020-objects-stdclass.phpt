--TEST--
phpser: stdClass round-trip (dynamic properties)
--EXTENSIONS--
phpser
--FILE--
<?php
$cases = [
    "empty"   => new stdClass(),
    "scalars" => (object) ["x" => 1, "y" => "two", "z" => 3.14],
    "nested"  => (object) ["inner" => (object) ["v" => 99]],
    "obj_arr" => (object) ["pts" => [(object) ["x" => 1], (object) ["x" => 2]]],
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
