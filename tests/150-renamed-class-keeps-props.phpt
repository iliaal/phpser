--TEST--
phpser: a cached object whose class was renamed keeps its property values on the incomplete class
--EXTENSIONS--
phpser
--XFAIL--
Known limitation (README "Unknown classes at decode"): declared-property objects encode as positional TAG_OBJECT_SLOTS with no property names, so when the class is not loadable the slot values are dropped. Native unserialize() keeps them.
--FILE--
<?php
require __DIR__ . '/145-app-fixtures.inc';

// Same-length swap: the payload now names a class that no longer exists, as
// after a namespace move or rename deployed under a warm cache.
$model = mk_eloquent_model(2);
$payload = str_replace('EloquentModelLike', 'EloquentModelGone', phpser_serialize($model));
$native = str_replace('EloquentModelLike', 'EloquentModelGone', serialize($model));

$ic = phpser_unserialize($payload);
$nic = unserialize($native);
$props = (array) $ic;
$nprops = (array) $nic;

var_dump(get_class($ic), $props['__PHP_Incomplete_Class_Name']);
var_dump(count($props) === count($nprops));
var_dump(($props["\0*\0attributes"] ?? null) === $model->getAttributes());
// Re-serializing the incomplete object must not silently lose the data.
var_dump(serialize(phpser_unserialize(phpser_serialize($ic))) === serialize($nic));
?>
--EXPECT--
string(22) "__PHP_Incomplete_Class"
string(17) "EloquentModelGone"
bool(true)
bool(true)
bool(true)
