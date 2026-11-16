<?php
// Encoder string-cache corpus: distinct-string counts on both sides of each
// cache resize point (32 to 32768 slots), with small and large top levels and
// repeated string pointers. string_cache_corpus_digest() hashes all encodings;
// run it under two builds to compare their wire bytes. $scale > 1 adds sizes.
function string_cache_unseeded(int $n, string $p = 's'): array {
    $rows = [];
    for ($r = 0; $r < 10; $r++) {
        $row = [];
        for ($i = $r; $i < $n; $i += 10) $row[] = $p . $i;
        $rows[] = $row;
    }
    return $rows;
}

function string_cache_corpus(int $scale = 1): Generator {
    $sizes = [1, 16, 17, 32, 33, 64, 65, 128, 129, 256, 257, 512, 513, 1024, 1025,
              2048, 2049, 4096, 4097, 8192, 8193];
    for ($s = 2; $s <= $scale; $s++) $sizes[] = 8193 * $s + $s;
    foreach ($sizes as $n) {
        yield "unseeded/$n" => string_cache_unseeded($n);
        // The same string pointers again, in new arrays after all first uses.
        $first = string_cache_unseeded($n, 'r');
        $second = [];
        foreach (array_reverse($first) as $row) $second[] = array_reverse($row);
        yield "revisit/$n" => [$first, $second];
        // Runtime-built keys under a two-key wrapper.
        $keyed = [];
        for ($i = 0; $i < $n; $i++) $keyed["k$i"] = $i % 7;
        yield "keys/$n" => ['m' => $keyed, 'again' => array_keys($keyed)];
    }
    // Large top levels (510 to 1000 rows) of distinct strings.
    foreach ([[510, 4], [513, 4], [600, 3], [600, 4], [1000, 3]] as [$rows, $per]) {
        $out = [];
        $k = 0;
        for ($r = 0; $r < $rows; $r++) {
            $row = [];
            for ($i = 0; $i < $per; $i++) $row[] = 't' . $k++;
            $out[] = $row;
        }
        yield "seeded/{$rows}x$per" => $out;
    }
    // Nested catalog: literal keys, runtime-built labels, 60 top-level rows.
    $catalog = [];
    for ($r = 0; $r < 60; $r++) {
        $attrs = [];
        for ($a = 0; $a < 10 + ($r * 7) % 16; $a++) {
            $attrs[] = ['label' => ucfirst("field $a label"), 'name' => "field_$a",
                        'validators' => ['nullable', ['date', 'string'][($r + $a) % 2]],
                        'isHidden' => $a % 5 === 4];
        }
        $catalog[] = ['document_type' => "doc_type_$r", 'label' => "Document Type $r",
                      'attributes' => $attrs];
    }
    yield 'catalog' => $catalog;
    // A top-level object with 300 dynamic properties.
    $obj = new stdClass;
    for ($i = 0; $i < 300; $i++) $obj->{"p$i"} = "v$i";
    yield 'object' => $obj;
    yield 'object-revisit' => [$obj, get_object_vars($obj)];
}

function string_cache_corpus_digest(int $scale = 1): string {
    $h = hash_init('sha256');
    foreach (string_cache_corpus($scale) as $name => $value) {
        $wire = phpser_serialize($value);
        if (phpser_unserialize($wire) != $value) throw new Exception("$name: round trip changed");
        hash_update($h, $name . ':' . strlen($wire) . ':' . $wire);
    }
    return hash_final($h);
}
