--TEST--
phpser: inline strings, pointer promotion, and dictionary content reuse
--EXTENSIONS--
phpser
--FILE--
<?php
// Unique first encounters stay inline; they do not populate the dictionary.
$strs = [];
for ($i = 0; $i < 50; $i++) $strs[] = "unique_$i";
$encoded = phpser_serialize($strs);
var_dump(ord($encoded[1]) === 0);
$rt = phpser_unserialize($encoded);
var_dump($rt === $strs);

// Mix of literal-interned (repeated) and unique strings.
$rows = [];
for ($i = 0; $i < 50; $i++) {
    $rows[] = ["id" => $i, "name" => "row_$i"]; // "id"/"name" repeat, "row_N" unique
}
$rt = phpser_unserialize(phpser_serialize($rows));
var_dump(serialize($rt) === serialize($rows));

// Equal bytes in separately allocated strings do not populate a dictionary
// by themselves before the content map exists. Keep this inline-only control.
$a = str_repeat("x", 8);
$b = str_repeat("x", 8);
$payload = [];
for ($i = 0; $i < 40; $i++) $payload[] = "filler_$i";
$payload[] = $a;
$payload[] = $b;
$encoded = phpser_serialize($payload);
var_dump(ord($encoded[1]) === 0);
var_dump(phpser_unserialize($encoded) === $payload);

// Seed 50 dictionary entries by repeating each pointer. An initial null
// selects the mixed-value path rather than the homogeneous string-run path.
// This crosses the content-map threshold with actual dictionary entries.
$seeds = [];
$payload = [null];
$header = "\x01\x32"; // V1, 50 dictionary entries
$body = "\x07\x97\x01\x00"; // mixed array, 151 elements, leading null
for ($i = 0; $i < 50; $i++) {
    $s = "seed_$i";
    $seeds[] = $s; // Keep every allocation alive throughout construction.
    $payload[] = $s;
    $payload[] = $s;
    $header .= chr(strlen($s)) . $s;
    // First occurrence stays inline even after the second promotes it.
    $body .= "\x0c" . chr(strlen($s)) . $s . "\x05" . chr($i);
}
foreach ($seeds as $i => $s) {
    // New allocation, same bytes: this must hit the content map rather than
    // emit another inline value. It also exercises entries backfilled when
    // the map was initialized, not just entries inserted afterward.
    $payload[] = substr("!" . $s, 1);
    $body .= "\x05" . chr($i);
}
$encoded = phpser_serialize($payload);
var_dump($encoded === $header . $body);
var_dump(phpser_unserialize($encoded) === $payload);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
