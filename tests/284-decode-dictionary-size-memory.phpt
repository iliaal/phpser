--TEST--
phpser: dictionary tables do not allocate an unused sentinel slot
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (PHP_DEBUG) die('skip page-boundary measurement requires a non-debug allocator');
if (getenv('USE_ZEND_ALLOC') === '0') die('skip requires the Zend allocator');
$before = memory_get_usage();
$probe = str_repeat('x', 100000);
if (memory_get_usage() - $before < 100000) die('skip request allocations are not tracked');
?>
--FILE--
<?php
function varint(int $v): string {
    $out = '';
    do {
        $byte = $v & 0x7f;
        $v >>= 7;
        $out .= chr($byte | ($v ? 0x80 : 0));
    } while ($v);
    return $out;
}
function dictionary_peak(int $count): int {
    // Empty strings are engine-interned, and the null result allocates
    // nothing. Only the dictionary pointer table contributes to the peak.
    $frame = "\x01" . varint($count) . str_repeat("\0", $count) . "\0";
    phpser_unserialize($frame);
    memory_reset_peak_usage();
    $start = memory_get_usage();
    phpser_unserialize($frame);
    return memory_get_peak_usage() - $start;
}
foreach ([4096, 8192, 32768] as $bytes) {
    $count = intdiv($bytes, PHP_INT_SIZE);
    $below = dictionary_peak($count - 1);
    $exact = dictionary_peak($count);
    $above = dictionary_peak($count + 1);
    // One extra pointer formerly rounded each exact-sized table to another
    // 4 KiB page. The lower bound and above-boundary control also prevent a
    // missing allocation or broken peak measurement from passing vacuously.
    echo "$bytes bytes: ";
    var_dump($exact >= $bytes && $exact === $below && $above > $exact);
}
?>
--EXPECT--
4096 bytes: bool(true)
8192 bytes: bool(true)
32768 bytes: bool(true)
