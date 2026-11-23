--TEST--
phpser: a scalar/string table encode stops emitting columns once a memory_limit error is pending
--DESCRIPTION--
Scalar-and-string TAG_TABLE encodes skip row duplication, so an OOM can trigger
inside enc_emit_table_string_column or subsequent columns instead. Under
deferred memory_limit errors (PHP 8.2.35+, 8.3.36+, 8.4.27+, 8.5.12+), the
column and string-emission loops must check PHPSER_HAS_OOM() so the request
ends with the ordinary fatal and runs shutdown functions rather than hitting
the 2x hard-limit _exit().
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
$rows = [];
$pad = str_repeat('x', 500);
for ($i = 0; $i < 20000; $i++) {
    $rows[] = ['id' => $i, 's1' => $pad . $i, 's2' => $pad . ($i + 20000)];
}
gc_mem_caches();
ini_set('memory_limit', (string)(memory_get_usage(true) + 2 * 1024 * 1024));
phpser_serialize($rows);
echo "no fatal\n";
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
shutdown
