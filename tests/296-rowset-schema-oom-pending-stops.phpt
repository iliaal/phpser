--TEST--
phpser: interning a wide rowset schema past memory_limit ends with the fatal, not a hard-limit process exit
--DESCRIPTION--
Since PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 a memory_limit overrun no longer
bails out of the allocation: the error is queued and the allocation succeeds
up to a hard limit that _exit()s the whole process. Rows that share one
string-key schema intern row 0's field names once; with the limit just above
current usage, interning them all passes the hard limit. Release builds need
2M long names; debug builds charge each field more allocator overhead and
need 3M short ones.
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
$row = [];
if (PHP_DEBUG) {
    for ($i = 0; $i < 3000000; $i++) $row["k$i"] = $i;
    $slack = 64 * 1024;
} else {
    for ($i = 0; $i < 2000000; $i++) $row["some_longer_key_name_$i"] = $i;
    $slack = 1024 * 1024;
}
gc_mem_caches();
ini_set('memory_limit', (string)(memory_get_usage(true) + $slack));
phpser_serialize([$row, $row]);
echo "no fatal\n";
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
