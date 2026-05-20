--TEST--
phpser: shapes from notable PHP serialize bug regressions (ext/standard/tests/serialize)
--EXTENSIONS--
phpser
--FILE--
<?php

// bug24063: scientific-notation floats with no digit after the decimal
$tiny_powers = [];
$v = 1.0;
for ($i = 1; $i < 12; $i++) {
    $v /= 10;
    $tiny_powers[] = $v;
}

// bug21957: object with mixed primitive props
class Bug21957 { public int $a = 7; public int $b = 2; }

// bug45706 / bug71940-style: simple objects (no __sleep/__wakeup) in arrays
class Identity { public string $role; public function __construct(string $r) { $this->role = $r; } }

// Big-ish payload with many distinct string keys (forces hash_map crossover)
$many_keys = [];
for ($i = 0; $i < 100; $i++) $many_keys["k_$i"] = $i;

// Strings with all special chars
$special = "a\nb\tc\rd\0e\\f\"g'h";

// Same content from different allocations
$s1 = "hello";
$s2 = "hel" . "lo";    // concat — likely a distinct allocation
$s3 = strrev(strrev("hello")); // runtime-built

$cases = [
    'tiny_powers'    => $tiny_powers,
    'bug21957_obj'   => new Bug21957(),
    'identities'     => [new Identity('admin'), new Identity('user')],
    'many_keys'      => $many_keys,
    'special_chars'  => $special,
    'duplicate_strs' => [$s1, $s2, $s3, "hello"],
    // Many nested arrays of same shape (stresses inline-cache + dict-upgrade)
    'rowset_100'     => array_map(
        fn($i) => ['id' => $i, 'name' => "n$i", 'meta' => ['a' => 1, 'b' => 2]],
        range(1, 100)
    ),
];

$fail = 0;
foreach ($cases as $name => $v) {
    $rt = phpser_unserialize(phpser_serialize($v));
    if (serialize($rt) !== serialize($v)) {
        echo "MISMATCH $name\n";
        $fail++;
    }
}
echo $fail ? "FAILED $fail\n" : "OK\n";
?>
--EXPECT--
OK
