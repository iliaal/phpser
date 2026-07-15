--TEST--
phpser: table columns deduplicate low-cardinality strings from distinct allocations
--EXTENSIONS--
phpser
--FILE--
<?php

function distinct_string_122(string $value): string {
    return substr($value . "\0", 0, strlen($value));
}

$states = ['pending', 'running', 'complete'];
$rows = [];
for ($i = 0; $i < 30; $i++) {
    $rows[] = ['state' => distinct_string_122($states[$i % count($states)])];
}

$blob = phpser_serialize($rows);
$roundtrip = phpser_unserialize($blob);

echo serialize($roundtrip) === serialize($rows) ? "roundtrip OK\n" : "roundtrip FAIL\n";
echo strlen($blob) < 100 ? "low_cardinality_dedup OK\n" : "low_cardinality_dedup FAIL\n";
?>
--EXPECT--
roundtrip OK
low_cardinality_dedup OK
