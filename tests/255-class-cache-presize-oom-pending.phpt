--TEST--
phpser: a class cache sized by the dictionary count stops at the memory_limit fatal
--DESCRIPTION--
Since PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 a memory_limit overrun no longer
bails out of the allocation: the error is queued and the allocation succeeds
up to a hard limit that _exit()s the whole process. The element count a
container declares sizes its table before any element is read, so the
per-element memory_limit checks cannot stop it.

Here a dictionary of 8M entries, all empty but one class name, fits its 64 MB
slot table 1 MB under the limit. With allowed_classes set, the first object
tag then allocates two 8 MB per-entry caches and a 64 MB class-entry cache
with no element in between; together they pass the hard limit.
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
class Bag {}
$n = 8 * 1024 * 1024;
$s = "\x01" . varint($n) . "\x03Bag" . str_repeat("\x00", $n - 1) . "\x0a\x00\x00";
gc_mem_caches();
ini_set('memory_limit', (string)(memory_get_usage(true) + 8 * ($n + 1) + 1024 * 1024));
phpser_unserialize($s, ['allowed_classes' => ['Bag']]);
echo "no fatal\n";
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
