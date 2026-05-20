--TEST--
phpser: nested array shapes (igbinary_007/010, ext/standard array tests)
--EXTENSIONS--
phpser
--FILE--
<?php
$cases = [
    // igbinary_007: nested packed
    'packed_of_packed' => [[1, 2, 3], [4, 5, 6], [7, 8, 9]],
    // igbinary_008: repeated string values (exercises compact_strings)
    'repeated_strs'    => ['foo', 'foo', 'foo'],
    'repeated_keys'    => ['one' => 1, 'two' => 2],
    'swapped_kv'       => ['kek' => 'lol', 'lol' => 'kek'],
    'empty_key'        => ['' => 'empty'],
    // igbinary_010: nested assoc
    'nested_assoc'     => ['a' => ['b' => 'c', 'd' => 'e'], 'f' => ['g' => 'h']],
    // deep but not pathological
    'medium_deep'      => (function () {
        $cur = ['leaf' => 42];
        for ($i = 0; $i < 100; $i++) $cur = ['next' => $cur, 'i' => $i];
        return $cur;
    })(),
    // packed of assocs (rowset shape)
    'rowset_small'     => array_map(
        fn($i) => ['id' => $i, 'name' => "row_$i", 'tags' => ['a', 'b', 'c']],
        range(1, 20)
    ),
    // assoc of mixed scalars
    'all_scalars'      => [
        'n'  => null,
        't'  => true,
        'f'  => false,
        'i'  => 42,
        'd'  => 3.14,
        's'  => 'hi',
    ],
    // arrays containing the same scalar repeated (verifies copy semantics)
    'dup_scalar'       => [42, 42, 42, 42],
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
