--TEST--
phpser: a table encode stops duplicating shared rows once a memory_limit error is pending
--DESCRIPTION--
Since PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 a memory_limit overrun no longer
bails out of the allocation: the error is queued and the allocation succeeds
up to a hard limit that _exit()s the whole process. The encoder must stop at
the queued error so the request ends with the ordinary fatal and its shutdown
functions still run.

The rows here share one table, so the TAG_TABLE gather duplicates each row
before any value is encoded; 40k copies need about 15 MB while the limit sits
2 MB above current usage.
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
$rows = array_fill(0, 40000, ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);
gc_mem_caches();
ini_set('memory_limit', (string)(memory_get_usage(true) + 2 * 1024 * 1024));
phpser_serialize($rows);
echo "no fatal\n";
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
