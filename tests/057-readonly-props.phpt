--TEST--
phpser: readonly properties (PHP 8.1+) survive round-trip
--SKIPIF--
<?php
if (!extension_loaded("phpser")) print "skip phpser not loaded";
if (PHP_VERSION_ID < 80100) print "skip PHP 8.1+ required for readonly";
?>
--FILE--
<?php

class Coord {
    public function __construct(
        public readonly float $x,
        public readonly float $y,
    ) {}
}

class WithReadonly {
    public readonly string $name;
    public int $counter = 0;
    public function __construct(string $name) { $this->name = $name; }
}

$cases = [
    'coord'         => new Coord(1.5, 2.5),
    'with_readonly' => new WithReadonly("alice"),
    'array_of'      => [new Coord(1.0, 2.0), new Coord(3.0, 4.0)],
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
