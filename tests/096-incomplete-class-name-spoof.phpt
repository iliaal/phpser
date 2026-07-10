--TEST--
phpser: a wire property named __PHP_Incomplete_Class_Name cannot spoof a denied class's preserved name
--EXTENSIONS--
phpser
--FILE--
<?php
// Attacker object carries a property literally named __PHP_Incomplete_Class_Name.
// Decoded under allowed_classes=>false it must become an incomplete "Evil"
// placeholder whose reserved name the wire property cannot overwrite — otherwise
// reserializing and decoding it with classes allowed would resurrect Spoof and
// run Spoof::__wakeup, defeating the filter across a store-reload cycle.
class Spoof { public function __wakeup() { echo "SPOOF_WAKEUP\n"; } }
#[\AllowDynamicProperties]
class Evil {}
$e = new Evil;
$e->{"__PHP_Incomplete_Class_Name"} = "Spoof";
$e->x = 1;

$inc = phpser_unserialize(phpser_serialize($e), ["allowed_classes" => false]);
$props = (array)$inc;                       // cast avoids the incomplete-object access warning
var_dump($props['__PHP_Incomplete_Class_Name']);
var_dump($props['x']);

$round = phpser_unserialize(phpser_serialize($inc));
var_dump(get_class($round) === 'Spoof');
?>
--EXPECT--
string(4) "Evil"
int(1)
bool(false)
