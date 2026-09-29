--TEST--
phpser: cached payloads decoded after a schema change (enum case, class rename, removed/extra props, readonly)
--EXTENSIONS--
phpser
--INI--
error_reporting=E_ALL
display_errors=1
--FILE--
<?php
// Each case encodes against the "old" class, then swaps the class name in the
// payload for a same-length "new" class. Dict entries are length-prefixed, so
// an equal-length swap keeps the frame well-formed: exactly what a deploy that
// changed the class definition under a warm cache looks like.
function evolve(string $payload, string $from, string $to): string {
    if (strlen($from) !== strlen($to)) throw new LogicException('names must match in length');
    return str_replace($from, $to, $payload);
}
function signed(string $frame, string $key): string {
    return $frame . hash_hmac('sha256', $frame, $key, true);
}
function try_signed(string $frame): string {
    try {
        $v = phpser_unserialize_signed(signed($frame, 'k'), 'k');
        return 'value ' . get_debug_type($v);
    } catch (Exception $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }
}

echo "-- enum case removed after encode\n";
enum StatusOld: string { case Active = 'active'; case Retired = 'retired'; }
enum StatusNew: string { case Active = 'active'; }
$gone = evolve(phpser_serialize(['s' => StatusOld::Retired, 'n' => 1]), 'StatusOld', 'StatusNew');
$kept = evolve(phpser_serialize(['s' => StatusOld::Active, 'n' => 1]), 'StatusOld', 'StatusNew');
var_dump(phpser_unserialize($gone));
var_dump(phpser_unserialize($kept)['s']);
echo try_signed($gone), "\n";
echo "native: ";
var_dump(@unserialize(evolve(serialize(['s' => StatusOld::Retired]), 'StatusOld', 'StatusNew')));

echo "-- class renamed (old name no longer loadable)\n";
class OrderV1 { public int $id = 7; public string $total = '9.99'; }
$renamed = phpser_unserialize(evolve(phpser_serialize(new OrderV1()), 'OrderV1', 'OrderZZ'));
var_dump(get_class($renamed), ((array) $renamed)['__PHP_Incomplete_Class_Name']);
$back = phpser_unserialize(phpser_serialize($renamed));
var_dump(get_class($back), ((array) $back)['__PHP_Incomplete_Class_Name']);
// Current behavior, pinned: the unnamed slot values are dropped, leaving only
// the name carrier (150 is the XFAIL for native-parity property retention).
require __DIR__ . '/145-app-fixtures.inc';
$gone = phpser_unserialize(str_replace('EloquentModelLike', 'EloquentModelGone', phpser_serialize(mk_eloquent_model(2))));
var_dump(get_class($gone), array_keys((array) $gone));

echo "-- property removed from a positional (slots) class\n";
class WideDto1 { public int $a = 1; public int $b = 2; public int $extra = 3; }
class NarrDto1 { public int $a = 0; public int $b = 0; }
$wide = evolve(phpser_serialize(new WideDto1()), 'WideDto1', 'NarrDto1');
var_dump(phpser_unserialize($wide));
echo try_signed($wide), "\n";
echo "native:\n";
var_dump(unserialize(evolve(serialize(new WideDto1()), 'WideDto1', 'NarrDto1')));

echo "-- property appended to a positional (slots) class\n";
class NarrDto2 { public int $a = 1; public int $b = 2; }
class WideDto2 { public int $a = 0; public int $b = 0; public int $extra = 99; }
var_dump(phpser_unserialize(evolve(phpser_serialize(new NarrDto2()), 'NarrDto2', 'WideDto2')));

echo "-- named extra key into a class without AllowDynamicProperties\n";
#[AllowDynamicProperties]
class DynSrc01 { public int $a = 1; }
class DynDst01 { public int $a = 0; }
$src = new DynSrc01();
$src->extra = 'x';
var_dump(phpser_unserialize(evolve(phpser_serialize($src), 'DynSrc01', 'DynDst01')));

echo "-- named extra key into a readonly class\n";
readonly class RoDst001 { public function __construct(public int $a = 0) {} }
$ro = evolve(phpser_serialize($src), 'DynSrc01', 'RoDst001');
try {
    var_dump(phpser_unserialize($ro));
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    phpser_unserialize_signed(signed($ro, 'k'), 'k');
} catch (Error $e) {
    echo 'signed ', get_class($e), ': ', $e->getMessage(), "\n";
}
echo "native: ";
try {
    var_dump(unserialize(evolve(serialize($src), 'DynSrc01', 'RoDst001')));
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo "-- readonly class with a default-object promoted param\n";
final class Opts { public function __construct(public int $retries = 3, public array $tags = ['x']) {} }
readonly class Cfg { public function __construct(public string $name = 'svc', public Opts $opts = new Opts()) {} }
$cfg = new Cfg();
$rt = phpser_unserialize(phpser_serialize([$cfg, new Cfg('other', $cfg->opts)]));
var_dump($rt[0] == $cfg, $rt[0]->opts === $rt[1]->opts, $rt[1]->name);
try {
    $rt[0]->opts->retries = 5;
    echo "inner object stays mutable: ", $rt[1]->opts->retries, "\n";
    $rt[0]->name = 'x';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECTF--
-- enum case removed after encode
NULL
enum(StatusNew::Active)
Exception: phpser: signed payload failed to decode
native: bool(false)
-- class renamed (old name no longer loadable)
string(22) "__PHP_Incomplete_Class"
string(7) "OrderZZ"
string(22) "__PHP_Incomplete_Class"
string(7) "OrderZZ"
string(22) "__PHP_Incomplete_Class"
array(1) {
  [0]=>
  string(27) "__PHP_Incomplete_Class_Name"
}
-- property removed from a positional (slots) class
NULL
Exception: phpser: signed payload failed to decode
native:

Deprecated: Creation of dynamic property NarrDto1::$extra is deprecated in %s on line %d
object(NarrDto1)#%d (3) {
  ["a"]=>
  int(1)
  ["b"]=>
  int(2)
  ["extra"]=>
  int(3)
}
-- property appended to a positional (slots) class
object(WideDto2)#%d (3) {
  ["a"]=>
  int(1)
  ["b"]=>
  int(2)
  ["extra"]=>
  int(99)
}
-- named extra key into a class without AllowDynamicProperties

Deprecated: Creation of dynamic property DynDst01::$extra is deprecated in %s on line %d
object(DynDst01)#%d (2) {
  ["a"]=>
  int(1)
  ["extra"]=>
  string(1) "x"
}
-- named extra key into a readonly class
Error: Cannot create dynamic property RoDst001::$extra
signed Error: Cannot create dynamic property RoDst001::$extra
native: Error: Cannot create dynamic property RoDst001::$extra
-- readonly class with a default-object promoted param
bool(true)
bool(true)
string(5) "other"
inner object stays mutable: 5
Error: Cannot modify readonly property Cfg::$name
