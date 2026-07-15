--TEST--
phpser: table columns deduplicate nested string vectors from distinct allocations
--EXTENSIONS--
phpser
--FILE--
<?php

function distinct_string_121(string $value): string {
    return substr($value . "\0", 0, strlen($value));
}

$rows = [];
for ($i = 0; $i < 10; $i++) {
    $rows[] = [
        'tags' => [
            distinct_string_121('aa'),
            distinct_string_121('bb'),
            distinct_string_121('cc'),
        ],
    ];
}

$blob = phpser_serialize($rows);
$roundtrip = phpser_unserialize($blob);

echo serialize($roundtrip) === serialize($rows) ? "roundtrip OK\n" : "roundtrip FAIL\n";
echo strlen($blob) < 100 ? "nested_content_dedup OK\n" : "nested_content_dedup FAIL\n";
?>
--EXPECT--
roundtrip OK
nested_content_dedup OK
