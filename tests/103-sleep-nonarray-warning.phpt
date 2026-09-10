--TEST--
phpser: a non-array __sleep return emits E_WARNING and serializes null (matches native)
--EXTENSIONS--
phpser
--FILE--
<?php
// A non-array __sleep result warns and serializes null, matching native PHP.
// A thrown hook exception still aborts the frame.
class S_badsleep {
    public $x = 1;
    public function __sleep() { return "nope"; }
}
$blob = phpser_serialize(new S_badsleep());
var_dump(phpser_unserialize($blob));
?>
--EXPECTF--
Warning: phpser_serialize(): S_badsleep::__sleep() should return an array only containing the names of instance-variables to serialize in %s on line %d
NULL
