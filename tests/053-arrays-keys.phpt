--TEST--
phpser: array key edge cases (mixed int/string, large int, very negative, post-unset)
--EXTENSIONS--
phpser
--FILE--
<?php
$cases = [
    'mixed_int_str' => [0 => 'a', 'x' => 'b', 1 => 'c', 'y' => 'd'],
    'int_keys_big'  => [PHP_INT_MAX => 'max', PHP_INT_MIN => 'min', 0 => 'zero'],
    'sparse_int'    => [0 => 'a', 1 => 'b', 1000 => 'c', 1000000 => 'd'],
    'neg_to_pos'    => [-3 => 'a', -2 => 'b', -1 => 'c', 0 => 'd', 1 => 'e'],
    'post_unset_packed' => (function () {
        $a = [10, 20, 30, 40, 50];
        unset($a[1]);
        unset($a[3]);
        return $a;
    })(),
    'post_unset_assoc' => (function () {
        $a = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4];
        unset($a['b']);
        return $a;
    })(),
    'string_int_key' => ['0' => 'a', '1' => 'b'], // PHP coerces these to int keys
    'leading_zero'  => ['00' => 'a', '01' => 'b'], // these stay string
    'large_keys'    => ['a' => 1, 'bb' => 2, 'ccc' => 3, str_repeat('z', 100) => 4],
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
