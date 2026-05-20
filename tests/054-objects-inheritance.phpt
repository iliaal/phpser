--TEST--
phpser: inheritance, dynamic properties, mixed declared+dynamic
--EXTENSIONS--
phpser
--FILE--
<?php

class Animal
{
    public string $name;
    public int $age;
    public function __construct(string $name = "", int $age = 0)
    {
        $this->name = $name;
        $this->age = $age;
    }
}

class Dog extends Animal
{
    public string $breed;
    public function __construct(string $name = "", int $age = 0, string $breed = "")
    {
        parent::__construct($name, $age);
        $this->breed = $breed;
    }
}

#[\AllowDynamicProperties]
class Loose
{
    public int $declared = 0;
}

$cases = [
    'animal'      => new Animal("Bessie", 5),
    'dog'         => new Dog("Rex", 3, "labrador"),
    'dog_in_arr'  => [new Dog("Rex", 3, "lab"), new Dog("Buddy", 7, "poodle")],
    'loose_dyn'   => (function () {
        $o = new Loose();
        $o->declared = 42;
        $o->extra = "dynamic prop";
        $o->other = [1, 2, 3];
        return $o;
    })(),
    'parent_of_self' => (function () {
        // An array containing two distinct instances of the same class
        return [new Animal("A", 1), new Animal("B", 2)];
    })(),
];

$fail = 0;
foreach ($cases as $name => $v) {
    $rt = phpser_unserialize(phpser_serialize($v));
    if (serialize($rt) !== serialize($v)) {
        echo "MISMATCH $name\n";
        echo "  exp: " . serialize($v) . "\n";
        echo "  got: " . serialize($rt) . "\n";
        $fail++;
    }
}
echo $fail ? "FAILED $fail\n" : "OK\n";
?>
--EXPECT--
OK
