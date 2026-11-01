--TEST--
phpser: autoloaders and unserialize_callback_func get their own native unserialize() reference table
--EXTENSIONS--
phpser
--INI--
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
class RealCC {}
$GLOBALS['inner'] = str_replace('RealCC', 'GoneCC', phpser_serialize([new RealCC]));

function nested(string $c): void {
    echo "$c: ", json_encode(unserialize('a:2:{i:0;s:3:"own";i:1;R:2;}')), "\n";
}

class Outer implements Serializable {
    public function serialize() { return ''; }
    public function unserialize($d) { phpser_unserialize($GLOBALS['inner']); }
}

echo "-- callback\n";
ini_set('unserialize_callback_func', 'nested');
@unserialize('a:2:{i:0;s:5:"outer";i:1;C:5:"Outer":0:{}}');

echo "-- autoload\n";
ini_set('unserialize_callback_func', '');
spl_autoload_register('nested');
unserialize('a:2:{i:0;s:5:"outer";i:1;C:5:"Outer":0:{}}');
?>
--EXPECT--
-- callback
GoneCC: ["own","own"]
-- autoload
GoneCC: ["own","own"]
