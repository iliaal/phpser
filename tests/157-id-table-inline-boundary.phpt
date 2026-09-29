--TEST--
phpser: back-references resolve across the id table's move from stack to heap
--DESCRIPTION--
The decoder keeps the first 8 ids in stack storage and copies them to the heap
on the 9th. Graphs sized around that boundary must keep every early
back-reference (objects, a PHP reference, a shared array) pointing at the
right entity after the copy.
--EXTENSIONS--
phpser
--FILE--
<?php
foreach ([1, 7, 8, 9, 16, 17, 33] as $n) {
    $objs = [];
    for ($i = 0; $i < $n; $i++) {
        $o = new stdClass;
        $o->i = $i;
        $objs[] = $o;
    }
    $x = 'r';
    $shared = [$n, 'tags'];
    $v = ['objs' => $objs, 'ref' => &$x, 'shared' => $shared,
          'again' => $objs, 'ref2' => &$x, 'shared2' => $shared];
    $rt = phpser_unserialize(phpser_serialize($v));

    $same = true;
    for ($i = 0; $i < $n; $i++) {
        $same = $same && $rt['objs'][$i] === $rt['again'][$i]
            && $rt['objs'][$i]->i === $i;
    }
    $rt['ref'] = 'changed';
    echo "$n: ", $same ? 'objects ok' : 'objects BROKEN', ', ',
        $rt['ref2'] === 'changed' ? 'ref ok' : 'ref BROKEN', ', ',
        $rt['shared'] === [$n, 'tags'] && $rt['shared2'] === [$n, 'tags']
            ? 'shared ok' : 'shared BROKEN', "\n";
}
?>
--EXPECT--
1: objects ok, ref ok, shared ok
7: objects ok, ref ok, shared ok
8: objects ok, ref ok, shared ok
9: objects ok, ref ok, shared ok
16: objects ok, ref ok, shared ok
17: objects ok, ref ok, shared ok
33: objects ok, ref ok, shared ok
