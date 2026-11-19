--TEST--
phpser: an object-property encode past memory_limit ends with the fatal, not a hard-limit process exit
--DESCRIPTION--
Since PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 a memory_limit overrun no longer
bails out of the allocation: the error is queued and the allocation succeeds
up to a hard limit that _exit()s the whole process. Once a property value
sees the queued error, the remaining property names must not be interned;
with 2M dynamic properties and the limit 1 MB above current usage, interning
them all passes the hard limit.
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit requires Zend allocator');
?>
--INI--
memory_limit=1G
--FILE--
<?php
register_shutdown_function(function () { echo "shutdown\n"; });
$o = new stdClass;
for ($i = 0; $i < 2000000; $i++) $o->{"some_longer_key_name_$i"} = $i;
gc_mem_caches();
ini_set('memory_limit', (string)(memory_get_usage(true) + 1024 * 1024));
phpser_serialize([$o, 1]);
echo "no fatal\n";
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
