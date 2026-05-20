--TEST--
phpser: string round-trip (binary-safe, multibyte, long, exotic) — ext/standard 006, igbinary_006
--SKIPIF--
<?php if (!extension_loaded("phpser")) print "skip phpser not loaded"; ?>
--FILE--
<?php
$cases = [
    'empty'        => '',
    'ascii'        => 'foobar',
    'with_null'    => "before\x00after",
    'all_bytes'    => implode('', array_map('chr', range(0, 255))),
    'utf8_latin1'  => 'Æthelthryth',
    'utf8_cjk'     => 'すしのり 🍣',
    'utf8_emoji'   => '👨‍👩‍👧‍👦 family',
    'utf8_combining' => "e\u{0301}\u{0302}\u{0303}", // e with multiple combining marks
    'long_127'     => str_repeat('x', 127),   // last 1-byte varint length
    'long_128'     => str_repeat('y', 128),   // first 2-byte varint length
    'long_16383'   => str_repeat('z', 16383), // last 2-byte varint length
    'long_16384'   => str_repeat('a', 16384), // first 3-byte varint length
    'long_64k'     => str_repeat('b', 65535),
    'long_1m'      => str_repeat("c", 1 << 20),
    'newlines'     => "line1\nline2\rline3\r\nline4",
    'tabs_etc'     => "\t\v\f\b",
];
$fail = 0;
foreach ($cases as $name => $v) {
    $rt = phpser_unserialize(phpser_serialize($v));
    if ($v !== $rt) {
        printf("MISMATCH %s (len_orig=%d len_rt=%d)\n",
               $name, strlen($v), strlen((string)$rt));
        $fail++;
    }
}
echo $fail ? "FAILED $fail\n" : "OK\n";
?>
--EXPECT--
OK
