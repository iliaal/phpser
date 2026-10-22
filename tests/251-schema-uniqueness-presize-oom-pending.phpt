--TEST--
phpser: a schema key count past memory_limit stops the uniqueness check with the fatal
--DESCRIPTION--
Since PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 a memory_limit overrun no longer
bails out of the allocation: the error is queued and the allocation succeeds
up to a hard limit that _exit()s the whole process. The element count a
container declares sizes its table before any element is read, so the
per-element memory_limit checks cannot stop it.

Here a TAG_ASSOC_DICT declares 600k schema keys; the 4.8 MB key array fits
under the limit, 8 MB above current usage, but the hash set that checks the
keys for duplicates presizes to about 40 MB.
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0') die('skip memory_limit requires Zend allocator');
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
$s = "\x01\x01\x01a\x13" . varint($n) . str_repeat("\x00", 2 * $n);
gc_mem_caches();
ini_set('memory_limit', (string)(memory_get_usage(true) + 8 * 1024 * 1024));
phpser_unserialize($s);
echo "no fatal\n";
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
