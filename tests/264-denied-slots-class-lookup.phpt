--TEST--
phpser: denied positional objects resolve a long dictionary class name once, not once per object
--EXTENSIONS--
phpser
--FILE--
<?php
function vi($n) {
    $s = '';
    while ($n >= 128) { $s .= chr(($n & 127) | 128); $n >>= 7; }
    return $s . chr($n);
}
$name = str_repeat('A', 400000);
$count = 50000;
$dict = "\x02\x01" . vi(strlen($name)) . $name;
$frames = [
    'keyed' => $dict . "\x07" . vi($count) . str_repeat("\x0a\x00\x00", $count),
    'slots' => $dict . "\x07" . vi($count) . str_repeat("\x12\x00\x00", $count),
];
$time = [];
foreach ([['allowed_classes' => false], ['allowed_classes' => ['Foo']]] as $options) {
    foreach ($frames as $shape => $frame) {
        $start = hrtime(true);
        $value = phpser_unserialize($frame, $options);
        $time[$shape] = hrtime(true) - $start;
        var_dump(count($value) === $count && $value[0] instanceof __PHP_Incomplete_Class);
        unset($value);
    }
    // Each lookup used to lowercase and hash the whole name again.
    var_dump($time['slots'] < 50 * $time['keyed'] + 250_000_000);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
