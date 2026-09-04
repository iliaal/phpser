--TEST--
phpser: CR-019 top-level object with 100+ props round-trips (intern seeding)
--EXTENSIONS--
phpser
--FILE--
<?php
// Seeding the encode intern cache from a top-level object's property count
// is allocation-strategy only (wire-identical); this pins that the seeding
// path (n > 64) round-trips every property exactly.
$o = new stdClass();
for ($i = 0; $i < 100; $i++) {
    $o->{"k$i"} = "v$i";
}
$rt = phpser_unserialize(phpser_serialize($o));
$ok = ($rt instanceof stdClass);
for ($i = 0; $i < 100 && $ok; $i++) {
    $ok = (($rt->{"k$i"} ?? null) === "v$i");
}
echo $ok ? "object_seed OK\n" : "object_seed FAIL\n";

// Declared-property objects take the same path via default_properties_count.
class SeededDto {
    public string $a = "a";
    public int $b = 2;
}
$d = new SeededDto();
$rd = phpser_unserialize(phpser_serialize($d));
echo ($rd instanceof SeededDto && $rd->a === "a" && $rd->b === 2)
    ? "dto_seed OK\n" : "dto_seed FAIL\n";
?>
--EXPECT--
object_seed OK
dto_seed OK
