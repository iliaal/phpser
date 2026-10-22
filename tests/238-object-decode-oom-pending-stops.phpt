--TEST--
phpser: an object decode past memory_limit stops at the fatal without running hooks
--DESCRIPTION--
Since PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 a memory_limit overrun no longer
bails out of the allocation: the error is queued and the allocation succeeds
up to a hard limit that _exit()s the whole process. The decoder must stop at
the queued error so the request ends with the ordinary fatal and its shutdown
functions still run.

No __wakeup() runs: the decode fails before deferred hooks dispatch.
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
class P {
    public $v;
    public $w = [];
    public function __wakeup() { echo "wakeup\n"; exit; }
}
$rows = [];
for ($i = 0; $i < 100000; $i++) { $o = new P; $o->v = $i; $o->w = [$i]; $rows[] = $o; }
$s = phpser_serialize($rows);
unset($rows);
gc_mem_caches();
ini_set('memory_limit', (string)(memory_get_usage(true) + 4 * 1024 * 1024));
phpser_unserialize($s);
echo "no fatal\n";
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
