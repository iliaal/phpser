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
var_dump(scalar_frame_corpus_digest(false) === 'ed745f7803c7348cfb3e350767b79cc2c9f0d5069d923dc31a0732e3958ce810');
// Integers beyond 32 bits exist only on 64-bit builds.
var_dump(PHP_INT_SIZE === 4 || scalar_frame_corpus_digest(true) === '42e869fd844501c78df402a6f93d9d7394edb14033af38e66daf914b384a0c3f');
?>
--EXPECT--
bool(true)
bool(true)
