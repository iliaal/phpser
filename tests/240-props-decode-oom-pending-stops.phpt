--TEST--
phpser: applying decoded data as properties stops at a pending memory_limit error
--DESCRIPTION--
Since PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 a memory_limit overrun no longer
bails out of the allocation: the error is queued and the allocation succeeds
up to a hard limit that _exit()s the whole process. The decoder must stop at
the queued error so the request ends with the ordinary fatal and its shutdown
functions still run.

With allowed_classes false, the __serialize() data decodes within the limit
and is then installed as properties of an incomplete object; the integer keys
become string property names, allocated one per element.
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
class Bag {
    public function __construct(public array $v = []) {}
    public function __serialize(): array { return $this->v; }
    public function __unserialize(array $data): void { $this->v = $data; }
}
$v = [];
for ($i = 0; $i < 300000; $i++) $v[] = (($i % 1000) * 7919) % 1000;
$s = phpser_serialize(new Bag($v));
unset($v);
gc_mem_caches();
ini_set('memory_limit', (string)(memory_get_usage(true) + 12 * 1024 * 1024));
phpser_unserialize($s, ['allowed_classes' => false]);
echo "no fatal\n";
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
