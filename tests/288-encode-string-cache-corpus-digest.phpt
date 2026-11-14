--TEST--
phpser: string-cache resize corpus keeps its wire digest and round-trips
--EXTENSIONS--
phpser
--FILE--
<?php
require __DIR__ . '/288-string-cache-corpus.inc';
// Digest of the corpus encoded by the build before the 4x cache growth
// (27daed0); string_cache_corpus_digest() also checks every round trip.
var_dump(string_cache_corpus_digest() === '5f1d618d2abe8fda86e1cfbf27b379568e7896873cad0c015c78f370b2079df8');
?>
--EXPECT--
bool(true)
