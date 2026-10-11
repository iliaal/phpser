--TEST--
phpser: shared arrays reached through reference-bearing objects re-encode without expansion
--EXTENSIONS--
phpser
--FILE--
<?php
function graph180(int $depth, bool $reference): string {
    $header = "\x02\x04\x08stdClass\x01p\x01a\x01b";
    $object = "\x0a\x00\x01\x01" . ($reference ? "\x11\x03\x02" : "\x03\x02");
    $firstArrayId = $reference ? 2 : 1;
    $graph = "\x18\x07\x01\x10\x00";
    for ($i = 1; $i <= $depth; $i++) {
        $graph = "\x18\x07\x02" . $graph . "\x10" . chr($firstArrayId + $i - 1);
    }
    return $header . "\x06\x03\x01\x02" . $object . "\x01\x03" . $graph . "\x01\x02\x00";
}

foreach ([false, true] as $reference) {
    foreach ([4, 12, 18] as $depth) {
        $frame = graph180($depth, $reference);
        $decoded = phpser_unserialize($frame, ['allowed_classes' => false]);
        $encoded = phpser_serialize($decoded);
        $copy = phpser_unserialize($encoded, ['allowed_classes' => false]);
        $node = $copy['b'];
        for ($i = 0; $i < $depth; $i++) {
            $node = $node[0];
        }
        $props = (array)$node[0];
        $bounded = strlen($encoded) < 8 * strlen($frame);
        echo ($bounded && $copy['a'] === null && $props['p'] === 1)
            ? "graph OK\n" : "graph FAIL\n";
    }
}

$x = 1;
$object = (object)['p' => &$x, 'q' => &$x];
$object->self = $object;
$shared = [$object];
$value = [$shared, $shared, $shared];
$copy = phpser_unserialize(phpser_serialize($value));
$copy[0][0]->p = 7;
echo ($copy[0][0] === $copy[1][0] && $copy[1][0] === $copy[2][0]
    && $copy[1][0]->q === 7 && $copy[2][0]->self === $copy[2][0])
    ? "object aliases OK\n" : "object aliases FAIL\n";
$copy[1][] = 8;
echo (count($copy[0]) === 1 && count($copy[1]) === 2 && count($copy[2]) === 1)
    ? "copy on write OK\n" : "copy on write FAIL\n";

$shared = [&$x];
$copy = phpser_unserialize(phpser_serialize([$shared, $shared, $shared]));
$copy[0][0] = 9;
echo ($copy[1][0] === 9 && $copy[2][0] === 9)
    ? "array references OK\n" : "array references FAIL\n";
?>
--EXPECT--
graph OK
graph OK
graph OK
graph OK
graph OK
graph OK
object aliases OK
copy on write OK
array references OK
