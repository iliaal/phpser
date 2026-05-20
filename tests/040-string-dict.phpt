--TEST--
phpser: string dictionary handles >32 unique strings (crosses hash_map threshold)
--EXTENSIONS--
phpser
--FILE--
<?php
// Force >32 unique strings to exercise the hash_map backfill path.
$strs = [];
for ($i = 0; $i < 50; $i++) $strs[] = "unique_$i";
$rt = phpser_unserialize(phpser_serialize($strs));
var_dump($rt === $strs);

// Mix of literal-interned (repeated) and unique strings.
$rows = [];
for ($i = 0; $i < 50; $i++) {
    $rows[] = ["id" => $i, "name" => "row_$i"]; // "id"/"name" repeat, "row_N" unique
}
$rt = phpser_unserialize(phpser_serialize($rows));
var_dump(serialize($rt) === serialize($rows));

// Same content from distinct allocations — content-dedup still works
// once threshold is crossed.
$a = str_repeat("x", 8);
$b = str_repeat("x", 8);
$payload = [];
for ($i = 0; $i < 40; $i++) $payload[] = "filler_$i"; // forces threshold
$payload[] = $a;
$payload[] = $b;
$rt = phpser_unserialize(phpser_serialize($payload));
var_dump($rt === $payload);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
