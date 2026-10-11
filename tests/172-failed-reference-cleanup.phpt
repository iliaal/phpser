--TEST--
phpser: failed references contain NULL before destructors inspect or retain them
--EXTENSIONS--
phpser
--FILE--
<?php
class FailedReference {
    public $value;
    public function __destruct() {
        var_dump($this->value);
        echo 'product=', $this->value * 3, "\n";
        $GLOBALS['retained'] =& $this->value;
    }
}
$frame = "\x01\x02\x0fFailedReference\x05value"
    . "\x11\x0a\x00\x02\x01\x10\x00\x63\x00";
var_dump(phpser_unserialize($frame, ['allowed_classes' => ['FailedReference']]));
var_dump($retained);
$retained = 42;
var_dump($retained);
$value = new stdClass;
$value->self =& $value;
$copy = phpser_unserialize(phpser_serialize($value));
var_dump($copy->self === $copy);
unset($value, $copy);
gc_collect_cycles();
?>
--EXPECT--
NULL
product=0
NULL
NULL
int(42)
bool(true)
