--TEST--
phpser: signed (trusted) decode uses add_new for assoc arrays — all key shapes round-trip == native
--EXTENSIONS--
phpser
--FILE--
<?php
// phpser_unserialize_signed decodes HMAC-authenticated bytes with the trusted
// fast path (zend_hash_*_add_new, skipping the dup-key find). Authenticated
// data comes from our encoder, which serializes unique-keyed HashTables, so
// add_new is safe. Verify every assoc key shape still round-trips identically
// to native serialize().
$key = str_repeat("k", 32);

function rt_ok($v) {
    global $key;
    $back = phpser_unserialize_signed(phpser_serialize_signed($v, $key), $key);
    return serialize($back) === serialize($v);
}

$cases = [
    'empty'          => [],
    'string_keys'    => ['id' => 1, 'name' => 'alice', 'created' => '2026-05-19'],
    'int_keys'       => [5 => 'a', 9 => 'b', 100 => 'c'],
    'mixed_keys'     => ['x' => 1, 7 => 2, 'y' => 3, 0 => 4],
    'numeric_string' => ['5' => 'five', '10' => 'ten'],   // PHP normalizes to int keys
    'sparse'         => [0 => 'a', 2 => 'c', 50 => 'z'],
    'nested'         => ['outer' => ['inner' => ['deep' => 42]], 'sib' => [1, 2, 3]],
    'rowset'         => array_map(fn($i) => [
                            'id' => $i, 'user_id' => 1000 + $i, 'name' => "row_$i",
                            'created_at' => '2026-05-19T12:00:00Z', 'tags' => ['a', 'b', 'c'],
                        ], range(0, 20)),
    'big_map'        => array_combine(
                            array_map(fn($i) => "field_$i", range(0, 200)),
                            range(0, 200)),
    'dup_value_strs' => ['a' => 'USD', 'b' => 'USD', 'c' => 'USD'],  // dup VALUES, unique keys
];

foreach ($cases as $name => $v) {
    echo $name, ': ', rt_ok($v) ? 'OK' : 'MISMATCH', "\n";
}

// Key invariant: no phantom buckets — count matches unique keys.
$m = phpser_unserialize_signed(phpser_serialize_signed($cases['big_map'], $key), $key);
echo 'big_map count: ', count($m), "\n";
echo 'big_map keys unique: ', (count($m) === count(array_unique(array_keys($m)))) ? 'yes' : 'no', "\n";
echo "done\n";
?>
--EXPECT--
empty: OK
string_keys: OK
int_keys: OK
mixed_keys: OK
numeric_string: OK
sparse: OK
nested: OK
rowset: OK
big_map: OK
dup_value_strs: OK
big_map count: 201
big_map keys unique: yes
done
