--TEST--
phpser: README scalar-run example matches its affine wire representation
--EXTENSIONS--
phpser
--FILE--
<?php
$readme = preg_replace('/\s+/', ' ', file_get_contents(__DIR__ . '/../README.md'));
$claim = '2. **Tagged scalar runs.** For example, serializing `range(1, 1000)` by '
    . 'itself emits a `PACKED_AFFINE` header, the length, and two zigzag varints '
    . '(base and step), not 1000 `(tag, varint)` pairs. Decode expands the run '
    . 'in one tight loop with no per-element tag dispatch.';
echo str_contains($readme, $claim) ? "complete claim OK\n" : "complete claim FAIL\n";

$values = range(1, 1000);
$encoded = phpser_serialize($values);
// V2, empty dictionary, AFFINE, length 1000, zigzag base 1, zigzag step 1.
echo bin2hex($encoded), "\n";
echo phpser_unserialize($encoded) === $values ? "round-trip OK\n" : "round-trip FAIL\n";
?>
--EXPECT--
complete claim OK
020017e8070202
round-trip OK
