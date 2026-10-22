--TEST--
phpser: a header dictionary decode past memory_limit stops at the fatal
--DESCRIPTION--
Since PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 a memory_limit overrun no longer
bails out of the allocation: the error is queued and the allocation succeeds
up to a hard limit that _exit()s the whole process. The decoder must stop at
the queued error so the request ends with the ordinary fatal and its shutdown
functions still run.

The header declares 600k distinct 3-byte strings, which the decoder
materializes before the value. Their 4.8 MB slot table fits under the limit,
6 MB above current usage, but the strings themselves need about 19 MB.
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit requires Zend allocator');
?>
--INI--
memory_limit=256M
--FILE--
<?php
register_shutdown_function(function () { echo "shutdown\n"; });
function varint(int $v): string {
    $out = '';
    while ($v >= 0x80) {
        $out .= chr(($v & 0x7f) | 0x80);
        $v >>= 7;
    }
    return $out . chr($v);
}
$n = 600000;
// Each entry is a length byte of 3 and the index as 3 big-endian bytes.
$parts = [];
for ($i = 0; $i < $n; $i += 4096) {
    $parts[] = pack('N*', ...array_map(fn($j) => (3 << 24) | $j, range($i, min($i + 4096, $n) - 1)));
}
$s = "\x01" . varint($n) . implode('', $parts) . "\x00";
unset($parts);
gc_mem_caches();
ini_set('memory_limit', (string)(memory_get_usage(true) + 6 * 1024 * 1024));
phpser_unserialize($s);
echo "no fatal\n";
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
