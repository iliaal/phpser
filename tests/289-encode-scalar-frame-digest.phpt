--TEST--
phpser: top-level scalar frames keep their wire digest and round-trip
--EXTENSIONS--
phpser
--INI--
phpser.key=scalar-frame-test-key-0123456789abcdef
--FILE--
<?php
require __DIR__ . '/289-scalar-frame-corpus.inc';
// Digests of the corpus encoded by the build before the scalar fast path
// (38499ce); scalar_frame_corpus_digest() also checks every round trip.
var_dump(scalar_frame_corpus_digest(false) === '645a6e3a1f80304a03d9c6b46a45b82e92c7d4298d3fbf93e4d6cad5d88dfe48');
// Integers beyond 32 bits exist only on 64-bit builds.
var_dump(PHP_INT_SIZE === 4 || scalar_frame_corpus_digest(true) === '2c495498e3d9e71bdb9db7ca0bf2f1c69a707987510acacdd235c44e01c32b0f');
?>
--EXPECT--
bool(true)
bool(true)
