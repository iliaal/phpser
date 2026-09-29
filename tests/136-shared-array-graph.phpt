--TEST--
phpser: shared arrays keep object identity, reference aliasing, nesting, and cycles equivalent to native
--EXTENSIONS--
phpser
--FILE--
<?php

function rt($v) { return phpser_unserialize(phpser_serialize($v)); }
function nat($v) { return unserialize(serialize($v)); }
function refcount_of(array $a): int {
    ob_start();
    debug_zval_dump($a);
    preg_match('/refcount\((\d+)\)/', ob_get_clean(), $m);
    return (int)$m[1] - 2;
}
function has_shared_tag(string $payload): bool {
    return strpos($payload, "\x18") !== false;
}

// 1. Objects inside a shared array stay one instance.
class Node136 { public $v; public function __construct($v) { $this->v = $v; } }
$o = new Node136(1);
$a = [$o, 'k'];
$d = rt([$a, $a, 'x' => [$a]]);
echo ($d[0][0] === $d[1][0] && $d[0][0] === $d['x'][0][0] && refcount_of($d[0]) === 3)
    ? "object identity OK\n" : "object identity FAIL\n";

// 2. A reference inside a shared array must stay an alias in both copies,
//    as in the source and native: writing through one copy's element is
//    visible in the other. Such arrays emit by value.
$x = 1;
$a = [&$x, 2];
$src = [$a, $a];
$d = rt($src);
$n = nat($src);
$d[0][0] = 5;
$n[0][0] = 5;
echo ($d[1][0] === 5 && $n[1][0] === 5) ? "reference aliasing OK\n" : "reference aliasing FAIL ({$d[1][0]})\n";
echo has_shared_tag(phpser_serialize($src)) ? "reference not shared FAIL\n" : "reference not shared OK\n";

// Same rule one array deeper: the reference sits in an RC-1 child.
$y = 1;
$mid = [[&$y]];
$src = ['p' => $mid, 'q' => $mid];
$d = rt($src);
$d['p'][0][0] = 9;
echo $d['q'][0][0] === 9 ? "nested reference aliasing OK\n" : "nested reference aliasing FAIL\n";

// 3. Nested shared arrays: the inner one is claimed before its parent and
//    back-referenced from both parents and the top level.
$inner = [1, 2, 'three'];
$outer = [$inner, $inner, 's'];
$src = [$outer, $outer, $inner];
$d = rt($src);
echo ($d === $src && refcount_of($d[2]) === 3 && refcount_of($d[0]) === 2)
    ? "nested shared OK\n" : "nested shared FAIL\n";

// 4. An object cycle back into the array being walked: the inner occurrence
//    emits by value (no back-reference to an unfinished array), later
//    occurrences share the finished array.
$o = new stdClass;
$a = [$o, 1];
$o->p = $a;
$src = ['x' => $a, 'y' => $a];
$d = rt($src);
echo ($d['x'][0] === $d['y'][0] && $d['x'][0]->p[0] === $d['x'][0]
      && $d['x'][1] === 1 && $d['x'][0]->p[1] === 1 && refcount_of($d['x']) === 2)
    ? "object cycle OK\n" : "object cycle FAIL\n";

// 5. Reference cycle through an array ($r = [&$r]) next to a shared array.
$r = [];
$r[0] = &$r;
$r[1] = [9, 9];
$src = [$r[1], $r, $r[1]];
$d = rt($src);
echo ($d[0] === [9, 9] && $d[2] === [9, 9] && is_array($d[1][0]) && $d[1][1] === [9, 9])
    ? "reference cycle OK\n" : "reference cycle FAIL\n";

// 6. Only nested arrays claim: the top level, empty arrays, and arrays seen
//    once with a single owner emit no prefix.
$t = [1, 2, 3];
echo has_shared_tag(phpser_serialize($t)) ? "top level FAIL\n" : "top level OK\n";
$e = [];
echo has_shared_tag(phpser_serialize([$e, $e, $e])) ? "empty FAIL\n" : "empty OK\n";
echo has_shared_tag(phpser_serialize([[random_int(1, 1)], [random_int(2, 2)]]))
    ? "unshared FAIL\n" : "unshared OK\n";

// 7. Deduplicated arrays compare equal to native results element-wise.
$lookup = ['en' => 'English', 'fr' => 'French'];
$src = ['users' => [['l' => $lookup], ['l' => $lookup], ['l' => $lookup]], 'all' => $lookup];
echo rt($src) === nat($src) ? "native parity OK\n" : "native parity FAIL\n";

// 8. A shared array inside a reference: the reference and the plain holder
//    see the same array; a write through the reference separates it.
$h = [1, 2];
$src = ['p' => &$h, 'q' => $h];
$d = rt($src);
$d['p'][] = 3;
echo ($d['q'] === [1, 2] && $d['p'] === [1, 2, 3]) ? "array in reference OK\n" : "array in reference FAIL\n";
?>
--EXPECT--
object identity OK
reference aliasing OK
reference not shared OK
nested reference aliasing OK
nested shared OK
object cycle OK
reference cycle OK
top level OK
empty OK
unshared OK
native parity OK
array in reference OK
