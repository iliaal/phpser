--TEST--
phpser: __sleep snapshots own dynamic property names across nested hooks
--EXTENSIONS--
phpser
--FILE--
<?php
class SleepKeyMutator {
    public function __serialize(): array {
        $GLOBALS['sleep_name'] = null;
        $GLOBALS['reused_name_storage'] = str_repeat('A', 32);
        return [];
    }
}

#[AllowDynamicProperties]
class SleepKeyOwner {
    public object $first;

    public function __sleep(): array {
        $names = ['first'];
        $names[] =& $GLOBALS['sleep_name'];
        return $names;
    }
}

$property = str_repeat('s', 32);
$value = new SleepKeyOwner();
$value->first = new SleepKeyMutator();
$value->{$property} = 'kept';
$GLOBALS['sleep_name'] = substr($property . 'x', 0, -1);

$roundtrip = phpser_unserialize(phpser_serialize($value));
$props = (array) $roundtrip;
echo (($props[$property] ?? null) === 'kept') ? "original_name OK\n" : "original_name FAIL\n";
echo array_key_exists(str_repeat('A', 32), $props) ? "reused_name FAIL\n" : "reused_name OK\n";
?>
--EXPECT--
original_name OK
reused_name OK
