--TEST--
phpser: a property value that queues a memory_limit error raises no competing diagnostic
--DESCRIPTION--
Since PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 a memory_limit overrun no longer
bails out of the allocation: the error is queued and the allocation succeeds
up to a hard limit that _exit()s the whole process. A value whose own
allocation queues the error still decodes, so installing it must not run the
property diagnostics: a dynamic-property deprecation (or a typed-property
TypeError) would report next to the memory_limit fatal.

Fillers use up the free pages of the current chunks, so the 900 kB string
below needs a new 2 MB chunk with the limit 64 kB above current usage.
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit requires Zend allocator');
?>
--INI--
memory_limit=256M
error_reporting=E_ALL
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
class C {}
$n = 900000;
$s = "\x01\x02\x01C\x01x\x0a\x00\x01\x01\x0c" . varint($n) . str_repeat('a', $n);
$fill = [];
gc_mem_caches();
$base = memory_get_usage(true);
while (memory_get_usage(true) === $base) $fill[] = str_repeat('f', $n);
$fill[] = str_repeat('f', $n);
ini_set('memory_limit', (string)(memory_get_usage(true) + 65536));
phpser_unserialize($s);
echo "no fatal\n";
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
