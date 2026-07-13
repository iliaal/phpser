--TEST--
phpser: __sleep accepts property names wrapped in references
--EXTENSIONS--
phpser
--FILE--
<?php
class SleepReferenceName {
    public int $selected = 7;
    public function __sleep(): array {
        $name = 'selected';
        return [&$name];
    }
}

$value = new SleepReferenceName;
$value->selected = 99;
$result = phpser_unserialize(phpser_serialize($value));
echo $result->selected, "\n";
?>
--EXPECT--
99
