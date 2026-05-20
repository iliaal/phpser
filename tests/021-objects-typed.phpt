--TEST--
phpser: typed-property objects (int, string, nullable, default value)
--EXTENSIONS--
phpser
--FILE--
<?php
class Point
{
    public function __construct(public int $x = 0, public int $y = 0) {}
}

class Named
{
    public function __construct(public string $name = "?", public ?int $age = null) {}
}

class Tags
{
    public array $tags = [];
    public function __construct(array $tags = []) { $this->tags = $tags; }
}

$cases = [
    "point_zero"  => new Point(),
    "point_set"   => new Point(3, 4),
    "named_null"  => new Named("alice"),
    "named_full"  => new Named("bob", 42),
    "tags_empty"  => new Tags(),
    "tags_full"   => new Tags(["red", "blue"]),
    "nested_obj"  => new Tags([new Point(1, 2), new Point(3, 4)]),
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
