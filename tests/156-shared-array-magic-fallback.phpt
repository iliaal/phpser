--TEST--
phpser: a shared __serialize() data array applied as properties releases its pin safely
--DESCRIPTION--
When __serialize() returns an array another holder also owns, the encoder
emits it as a SHARED_ARRAY. Decoding without __unserialize (class lacks it,
class denied by allowed_classes, or class not loaded) copies the elements into
properties and drops the data table, leaving the id-table pin as its only
holder. Debug builds asserted in decode_destroy before this was tracked.
--EXTENSIONS--
phpser
--FILE--
<?php
class WithHook {
    public $k; public $z; public $src;
    function __construct() { $this->src = ['k' => 1, 'z' => 'x']; }
    function __serialize(): array { return $this->src; }
    function __unserialize(array $a): void { $this->k = $a['k']; $this->z = $a['z']; }
}
class NoHook {
    public $k; public $z; public $src;
    function __construct() { $this->src = ['k' => 2, 'z' => 'y']; }
    function __serialize(): array { return $this->src; }
}

$denied = phpser_unserialize(phpser_serialize([new WithHook]), ['allowed_classes' => false]);
$a = (array)$denied[0];
var_dump(get_class($denied[0]), $a['k'], $a['z']);

$nohook = phpser_unserialize(phpser_serialize([new NoHook]));
var_dump(get_class($nohook[0]), $nohook[0]->k, $nohook[0]->z);

$p = phpser_serialize([new WithHook]);
$p = str_replace('WithHook', 'GoneHook', $p);
$gone = phpser_unserialize($p);
$a = (array)$gone[0];
var_dump(get_class($gone[0]), $a['k'], $a['z']);

$key = random_bytes(32);
$signed = phpser_unserialize_signed(phpser_serialize_signed([new NoHook], $key), $key);
var_dump($signed[0]->k);
?>
--EXPECT--
string(22) "__PHP_Incomplete_Class"
int(1)
string(1) "x"
string(6) "NoHook"
int(2)
string(1) "y"
string(22) "__PHP_Incomplete_Class"
int(1)
string(1) "x"
int(2)
