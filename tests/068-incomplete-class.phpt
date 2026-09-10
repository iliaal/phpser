--TEST--
phpser: unknown class fallback (incomplete_class_*.phpt — divergence from PHP)
--EXTENSIONS--
phpser
--FILE--
<?php


class Vanishing { public int $a = 7; public string $s = "hi"; }
$payload = phpser_serialize(new Vanishing());
$rt = phpser_unserialize($payload);
echo ($rt instanceof Vanishing && $rt->a === 7 && $rt->s === "hi") ? "vanishing_known OK\n" : "vanishing_known FAIL\n";

$sos = new SplObjectStorage();
$sos[new stdClass()] = "x";
$rt = phpser_unserialize(phpser_serialize($sos));
echo ($rt instanceof SplObjectStorage && $rt->count() === 1) ? "legacy_known OK\n" : "legacy_known FAIL\n";

enum Color { case Red; case Blue; }
$rt = phpser_unserialize(phpser_serialize(Color::Red));
echo ($rt === Color::Red) ? "enum_known OK\n" : "enum_known FAIL\n";

class WithNul { }
$payload = phpser_serialize(new WithNul());
$rt = phpser_unserialize($payload);
echo ($rt instanceof WithNul) ? "mangled_known OK\n" : "mangled_known FAIL\n";

class MySos extends SplObjectStorage {}
$sub = new MySos();
$sub[new stdClass()] = "data";
$rt = phpser_unserialize(phpser_serialize($sub));
echo ($rt instanceof MySos && $rt->count() === 1) ? "internal_sub OK\n" : "internal_sub FAIL\n";

class P_Magic {
    public int $n = 0;
    public function __serialize(): array { return ['n' => $this->n]; }
    public function __unserialize(array $d): void { $this->n = $d['n']; }
}
class C_Magic extends P_Magic {
    public function getN(): int { return $this->n; }
}
$c = new C_Magic();
$c->n = 11;
$rt = phpser_unserialize(phpser_serialize($c));
echo ($rt instanceof C_Magic && $rt->getN() === 11) ? "magic_inherited OK\n" : "magic_inherited FAIL\n";

// A filtered object must retain its original class name for reserialization.
class Recoverable { public int $a = 1; public string $s = "z"; }
$filtered = phpser_unserialize(phpser_serialize(new Recoverable()),
                               ["allowed_classes" => false]);
echo ($filtered instanceof __PHP_Incomplete_Class) ? "ic_filtered OK\n" : "ic_filtered FAIL\n";
$reencoded = phpser_serialize($filtered);
$back = phpser_unserialize($reencoded);  // Recoverable allowed this time
echo ($back instanceof Recoverable && $back->a === 1 && $back->s === "z")
    ? "ic_reencode_recovers OK\n" : "ic_reencode_recovers FAIL\n";
// The magic name-carrier member must not survive as a real property.
echo !isset($back->{"__PHP_Incomplete_Class_Name"}) ? "ic_no_magic_leak OK\n" : "ic_no_magic_leak FAIL\n";
// Parity: native recovers the class the same way.
$nfiltered = unserialize(serialize(new Recoverable()), ["allowed_classes" => false]);
$nback = unserialize(serialize($nfiltered));
echo ($nback instanceof Recoverable) ? "ic_native_parity OK\n" : "ic_native_parity FAIL\n";

?>
--EXPECT--
vanishing_known OK
legacy_known OK
enum_known OK
mangled_known OK
internal_sub OK
magic_inherited OK
ic_filtered OK
ic_reencode_recovers OK
ic_no_magic_leak OK
ic_native_parity OK
