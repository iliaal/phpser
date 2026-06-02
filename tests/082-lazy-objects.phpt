--TEST--
phpser: PHP 8.4 lazy objects (ghost/proxy) serialize with initialized values, not uninitialized slots
--SKIPIF--
<?php
if (!extension_loaded("phpser")) die("skip phpser not loaded");
if (PHP_VERSION_ID < 80400) die("skip requires PHP 8.4+ (lazy objects)");
if (!method_exists(ReflectionClass::class, "newLazyGhost")) die("skip lazy objects unavailable");
?>
--FILE--
<?php
// Regression guard for the property-slot encode fast path: a lazy ghost/proxy
// has uninitialized declared-property slots until its initializer fires. The
// fast path reads OBJ_PROP directly, so without the lazy guard it would
// serialize uninitialized values. Native serialize() triggers initialization
// (via get_properties); phpser must match.

final class Point {
    public function __construct(public int $x, public string $label) {}
}

$r = new ReflectionClass(Point::class);

// --- Lazy ghost: serialized while still uninitialized ---
$ghost = $r->newLazyGhost(function (Point $o) { $o->__construct(7, "hi"); });
$rt = phpser_unserialize(phpser_serialize($ghost));
echo "ghost x=", $rt->x, " label=", $rt->label, "\n";
echo "ghost matches native: ",
    (serialize($rt) === serialize(unserialize(serialize($ghost))) ? "yes" : "no"), "\n";

// --- Lazy proxy: serialized while still uninitialized ---
$proxy = $r->newLazyProxy(function (Point $o) { return new Point(42, "proxy"); });
$rt2 = phpser_unserialize(phpser_serialize($proxy));
echo "proxy x=", $rt2->x, " label=", $rt2->label, "\n";

// --- Already-initialized object still round-trips (fast path proper) ---
$plain = new Point(1, "plain");
$rt3 = phpser_unserialize(phpser_serialize($plain));
echo "plain x=", $rt3->x, " label=", $rt3->label, "\n";
echo "done\n";
?>
--EXPECT--
ghost x=7 label=hi
ghost matches native: yes
proxy x=42 label=proxy
plain x=1 label=plain
done
