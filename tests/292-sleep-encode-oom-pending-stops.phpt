--TEST--
phpser: a __sleep encode past memory_limit ends with the fatal, not a hard-limit process exit
--DESCRIPTION--
Since PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 a memory_limit overrun no longer
bails out of the allocation: the error is queued and the allocation succeeds
up to a hard limit that _exit()s the whole process. The __sleep member
snapshot and the member names it interns must stop at the queued error; with
the limit just above current usage, interning all 1M names passes the hard
limit.
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit requires Zend allocator');
?>
--INI--
memory_limit=512M
--FILE--
<?php
register_shutdown_function(function () { echo "shutdown\n"; });
#[AllowDynamicProperties]
class Sleeper {
    public $names;
    public function __sleep(): array { return $this->names; }
}
$o = new Sleeper;
for ($i = 0; $i < 1000000; $i++) $o->{"p$i"} = $i;
$o->names = array_keys(get_object_vars($o));
gc_mem_caches();
ini_set('memory_limit', (string)(memory_get_usage(true) + 8 * 1024 * 1024));
phpser_serialize($o);
echo "no fatal\n";
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
