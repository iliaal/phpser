--TEST--
phpser: a __sleep encode past memory_limit ends with the fatal, not a hard-limit process exit
--DESCRIPTION--
Since PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 a memory_limit overrun no longer
bails out of the allocation: the error is queued and the allocation succeeds
up to a hard limit that _exit()s the whole process. The __sleep member
snapshot and the member names it interns must stop at the queued error; with
the limit just above current usage, interning all the names passes the hard
limit. Debug builds charge each property more allocator overhead, so they
need 3M members where release builds need 1M.
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit requires Zend allocator');
// Older patch releases bail out of the allocation, so the hard limit the test
// guards against does not exist there.
$v = PHP_VERSION_ID;
if ($v < 80235 || ($v >= 80300 && $v < 80336) || ($v >= 80400 && $v < 80427) || ($v >= 80500 && $v < 80512)) {
    die('skip memory_limit errors are not deferred before PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12');
}
?>
--INI--
memory_limit=2G
--FILE--
<?php
register_shutdown_function(function () { echo "shutdown\n"; });
#[AllowDynamicProperties]
class Sleeper {
    public $names;
    public function __sleep(): array { return $this->names; }
}
$o = new Sleeper;
$n = PHP_DEBUG ? 3000000 : 1000000;
for ($i = 0; $i < $n; $i++) $o->{"p$i"} = $i;
$o->names = array_keys(get_object_vars($o));
gc_mem_caches();
ini_set('memory_limit', (string)(memory_get_usage(true) + 8 * 1024 * 1024));
phpser_serialize($o);
echo "no fatal\n";
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
