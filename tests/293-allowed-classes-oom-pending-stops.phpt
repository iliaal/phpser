--TEST--
phpser: an allowed_classes list past memory_limit ends with the fatal, not a hard-limit process exit
--DESCRIPTION--
Since PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 a memory_limit overrun no longer
bails out of the allocation: the error is queued and the allocation succeeds
up to a hard limit that _exit()s the whole process. Each allowed_classes entry
is copied lowercased into a lookup set, so the copy loop must stop at the
queued error; with the limit just above current usage, copying all 2M names
passes the hard limit.
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
$names = [];
for ($i = 0; $i < 2000000; $i++) $names[] = "Some\\Namespace\\ClassName$i";
$payload = phpser_serialize([1, 2, 3]);
gc_mem_caches();
ini_set('memory_limit', (string)(memory_get_usage(true) + 8 * 1024 * 1024));
phpser_unserialize($payload, ['allowed_classes' => $names]);
echo "no fatal\n";
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
