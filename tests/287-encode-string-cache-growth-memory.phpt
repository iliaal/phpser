--TEST--
phpser: encoder string cache quadruples through 2048 slots, then doubles
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0') die('skip requires the Zend allocator');
$before = memory_get_usage();
$probe = [];
for ($i = 0; $i < 1000; $i++) $probe[] = str_repeat('x', 100);
if (memory_get_usage() - $before < 100000) die('skip request allocations are not tracked');
?>
--FILE--
<?php
// Peak bytes added by one encode; the input and its strings already exist.
function encode_peak(array $value): int {
    phpser_serialize($value);
    memory_reset_peak_usage();
    $start = memory_get_usage();
    $payload = phpser_serialize($value);
    return memory_get_peak_usage() - $start;
}

// $n distinct heap strings under a 10-element list, too small a top level to
// seed the cache, so it starts at 32 slots and grows when the next entry would
// pass half the capacity.
function unseeded(int $n): array {
    $rows = [];
    for ($r = 0; $r < 10; $r++) {
        $row = [];
        for ($i = $r; $i < $n; $i += 10) $row[] = "s$i";
        $rows[] = $row;
    }
    return $rows;
}

// 600 top-level rows (a 4096-slot seed) of $per distinct strings each.
function seeded(int $per): array {
    $rows = [];
    $k = 0;
    for ($r = 0; $r < 600; $r++) {
        $row = [];
        for ($i = 0; $i < $per; $i++) $row[] = 't' . $k++;
        $rows[] = $row;
    }
    return $rows;
}

$slot = PHP_INT_SIZE === 8 ? 16 : 8;  // sizeof(intern_slot)
$bytes = fn(int $slots) => $slots * $slot;

// Entry 1025 grows the 2048-slot cache. Quadrupling peaks at 2048+8192 slots
// versus 512+2048 for 1024 entries (about +7680); doubling would peak at
// 2048+4096 versus 1024+2048 (about +3072). Require at least +6144.
$d1 = encode_peak(unseeded(1025)) - encode_peak(unseeded(1024));
echo '2048 -> 8192: ', $d1 >= $bytes(6144) ? 'quadrupled' : "not quadrupled ($d1)", "\n";

// Entry 4097 grows the 8192-slot cache. Doubling peaks at 8192+16384 slots
// versus 2048+8192 for 4096 entries (about +14336); quadrupling would peak at
// 8192+32768 (about +30720). Require at most +22528.
$d2 = encode_peak(unseeded(4097)) - encode_peak(unseeded(4096));
echo '8192 -> 16384: ', $d2 <= $bytes(22528) ? 'doubled' : "over-allocated ($d2)", "\n";

// 2400 entries grow the seeded 4096-slot cache; 1800 entries fit it. Doubling
// adds an 8192-slot table at the peak, quadrupling a 16384-slot one. Require
// at most +12288 slots.
$d3 = encode_peak(seeded(4)) - encode_peak(seeded(3));
echo 'seeded 4096 -> 8192: ', $d3 <= $bytes(12288) ? 'doubled' : "over-allocated ($d3)", "\n";
?>
--EXPECT--
2048 -> 8192: quadrupled
8192 -> 16384: doubled
seeded 4096 -> 8192: doubled
