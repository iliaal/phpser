--TEST--
phpser: ~390 KB (native) wallet payload round-trips under memory_limit=32M
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0') die('skip memory_limit requires Zend allocator');
?>
--INI--
memory_limit=32M
--FILE--
<?php
require __DIR__ . '/145-app-fixtures.inc';

$wallet = mk_wallet();
$native = serialize($wallet);
$payload = phpser_serialize($wallet);
$signed = phpser_serialize_signed($wallet, 'wallet-key');
printf("native>=350KB: %s phpser<native: %s signed_overhead: %d\n",
    var_export(strlen($native) >= 350000, true),
    var_export(strlen($payload) < strlen($native), true),
    strlen($signed) - strlen($payload));

$rt = phpser_unserialize($payload);
echo "unsigned serialize_equal: ", var_export(serialize($rt) === $native, true), "\n";
unset($rt);
$rt = phpser_unserialize_signed($signed, 'wallet-key');
echo "signed serialize_equal: ", var_export(serialize($rt) === $native, true), "\n";
echo "groups=", count($rt), " records=", array_sum(array_map(fn($g) => count($g->records), $rt)),
    " documents=", array_sum(array_map(fn($g) => count($g->documents), $rt)), "\n";
unset($rt);

// Several decoded copies alive at once, as a request that loads a few wallets.
$live = [];
for ($i = 0; $i < 8; $i++) $live[] = phpser_unserialize($payload);
echo "8 live copies: ", count($live), " peak<24MiB: ", var_export(memory_get_peak_usage() < 24 * 1048576, true), "\n";
?>
--EXPECT--
native>=350KB: true phpser<native: true signed_overhead: 32
unsigned serialize_equal: true
signed serialize_equal: true
groups=10 records=40 documents=100
8 live copies: 8 peak<24MiB: true
