--TEST--
phpser: a TAG_ASSOC count past memory_limit ends with the fatal, not a hard-limit process exit
--DESCRIPTION--
Since PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 a memory_limit overrun no longer
bails out of the allocation: the error is queued and the allocation succeeds
up to a hard limit that _exit()s the whole process. The element count a
container declares sizes its table before any element is read, so the
per-element memory_limit checks cannot stop it.

Here 500k inline string keys presize one hash table of about 20 MB while the
limit sits 2 MB above current usage, past the hard limit in one allocation.
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
$a = [];
for ($i = 0; $i < 500000; $i++) $a["k$i"] = $i;
$s = phpser_serialize($a);
unset($a);
gc_mem_caches();
ini_set('memory_limit', (string)(memory_get_usage(true) + 2 * 1024 * 1024));
phpser_unserialize($s);
echo "no fatal\n";
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
