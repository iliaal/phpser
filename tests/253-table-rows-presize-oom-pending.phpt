--TEST--
phpser: a TAG_TABLE row count past memory_limit stops building the row list with the fatal
--DESCRIPTION--
Since PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 a memory_limit overrun no longer
bails out of the allocation: the error is queued and the allocation succeeds
up to a hard limit that _exit()s the whole process. The element count a
container declares sizes its table before any element is read, so the
per-element memory_limit checks cannot stop it.

Here a one-column table declares 4M+1 rows. The 64 MB column buffer fits
under the limit, 68 MB above current usage, but the row list it scatters into
presizes to the next power of two, 128 MB. Trailing padding, which the
unsigned decoder tolerates, raises the materialization budget to admit the
row count.
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
$n = (1 << 22) + 1;
$s = "\x01\x01\x01a\x15" . varint($n) . varint(1) . "\x00\x08" . str_repeat("\x00", $n);
$s .= str_repeat("\x00", 28 * 1024 * 1024 - strlen($s));
gc_mem_caches();
ini_set('memory_limit', (string)(memory_get_usage(true) + 68 * 1024 * 1024));
phpser_unserialize($s);
echo "no fatal\n";
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
