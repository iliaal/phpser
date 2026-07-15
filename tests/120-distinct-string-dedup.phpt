--TEST--
phpser: table columns deduplicate equal strings from distinct allocations
--EXTENSIONS--
phpser
--FILE--
<?php

function distinct_string_120(string $value): string {
    return substr($value . "\0", 0, strlen($value));
}

$rows = [];
for ($i = 0; $i < 10; $i++) {
    $rows[] = [
        'created_at' => distinct_string_120('2026-05-19T12:00:00Z'),
    ];
}

$blob = phpser_serialize($rows);
$roundtrip = phpser_unserialize($blob);

echo serialize($roundtrip) === serialize($rows) ? "roundtrip OK\n" : "roundtrip FAIL\n";
echo strlen($blob) < 100 ? "content_dedup OK\n" : "content_dedup FAIL\n";
?>
--EXPECT--
roundtrip OK
content_dedup OK
