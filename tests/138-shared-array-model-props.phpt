--TEST--
phpser: one runtime-built array held by two properties (Eloquent attributes/original) encodes once and decodes shared
--EXTENSIONS--
phpser
--FILE--
<?php

class Model138 {
    protected $connection = 'mysql';
    protected $table = 'documents';
    public $exists = true;
    protected $attributes = [];
    protected $original = [];
    protected $changes = [];

    public function fill(array $attributes): static {
        $this->attributes = $attributes;
        return $this;
    }
    // Eloquent's syncOriginal(): original becomes the same zend_array.
    public function syncOriginal(): static {
        $this->original = $this->attributes;
        return $this;
    }
    public function attributes(): array { return $this->attributes; }
    public function original(): array { return $this->original; }
}

// Heap strings built at runtime, never literals, as a database driver returns.
function attrs138(): array {
    $a = ['id' => 1234];
    foreach (['title', 'slug', 'body', 'status', 'owner_email', 'created_at',
              'updated_at', 'mime', 'path', 'checksum'] as $i => $k) {
        $a[$k] = $k . '-value-' . str_repeat(chr(97 + $i), 8 + $i);
    }
    $a['size'] = 99887;
    return $a;
}

function refcount_of(array $a): int {
    ob_start();
    debug_zval_dump($a);
    preg_match('/refcount\((\d+)\)/', ob_get_clean(), $m);
    return (int)$m[1] - 2;
}

$once = (new Model138)->fill(attrs138());
$twice = (new Model138)->fill(attrs138())->syncOriginal();

$s_once = phpser_serialize($once);
$s_twice = phpser_serialize($twice);
// The second holder costs a back-reference (tag + id varint) and one prefix
// byte, not a second copy of the ~300-byte array.
$delta = strlen($s_twice) - strlen($s_once);
echo $delta <= 4 ? "size delta OK\n" : "size delta FAIL ($delta)\n";
if (function_exists('igbinary_serialize')) {
    $ig = strlen(igbinary_serialize($twice));
    echo strlen($s_twice) <= $ig ? "vs igbinary OK\n" : "vs igbinary FAIL (" . strlen($s_twice) . "/$ig)\n";
} else {
    echo "vs igbinary OK\n";
}

$d = phpser_unserialize($s_twice);
echo ($d instanceof Model138 && $d->attributes() === $twice->attributes()
      && $d->original() === $twice->original())
    ? "roundtrip OK\n" : "roundtrip FAIL\n";
$rc = refcount_of($d->attributes());
echo $rc === 2 ? "shared refcount OK\n" : "shared refcount FAIL ($rc)\n";

// Dirty-checking still works: changing one property leaves the other intact.
$d->fill(['id' => 1] + $d->attributes());
echo ($d->original() === $twice->original() && $d->attributes()['id'] === 1)
    ? "dirty tracking OK\n" : "dirty tracking FAIL\n";

// A collection of models, each with its own shared pair.
$models = [];
for ($i = 0; $i < 10; $i++) $models[] = (new Model138)->fill(attrs138())->syncOriginal();
$dm = phpser_unserialize(phpser_serialize($models));
$ok = count($dm) === 10;
foreach ($dm as $m) {
    if ($m->attributes() !== $m->original() || refcount_of($m->attributes()) !== 2) $ok = false;
}
echo $ok ? "collection OK\n" : "collection FAIL\n";
?>
--EXPECT--
size delta OK
vs igbinary OK
roundtrip OK
shared refcount OK
dirty tracking OK
collection OK
