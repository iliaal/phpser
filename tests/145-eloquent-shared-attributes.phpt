--TEST--
phpser: Eloquent-like model with attributes === original round-trips by value
--EXTENSIONS--
phpser
--FILE--
<?php
require __DIR__ . '/145-app-fixtures.inc';

function model_state(EloquentModelLike $m): array {
    return (fn() => get_object_vars($this))->call($m);
}

$single = mk_eloquent_model(3);
$coll = mk_eloquent_collection(30);

foreach (['model' => $single, 'collection' => $coll] as $label => $value) {
    foreach (['fresh', 'materialized'] as $state) {
        if ($state === 'materialized') {
            // get_object_vars() builds the property table, which switches the
            // encoder to a different object path.
            foreach ($label === 'model' ? [$value] : $value->all() as $m) model_state($m);
        }
        $rt = phpser_unserialize(phpser_serialize($value));
        echo "$label/$state serialize_equal: ", var_export(serialize($rt) === serialize($value), true), "\n";
    }
}

$rt = phpser_unserialize(phpser_serialize($coll));
$ok = true;
foreach ($rt->all() as $i => $m) {
    $src = $coll->all()[$i];
    $ok = $ok && $m instanceof EloquentModelLike
        && $m->getAttributes() === $src->getAttributes()
        && $m->getOriginal() === $m->getAttributes()
        && model_state($m) === model_state($src);
}
echo "collection per-model state: ", var_export($ok, true), "\n";

$attrs = $rt->all()[7]->getAttributes();
var_dump(count($attrs), $attrs['status'], $attrs['type'], $attrs['amount'], $attrs['size'], $attrs['deleted_at']);
var_dump(json_decode($attrs['metadata'], true)['original_name']);
var_dump(model_state($rt->all()[0])['casts']['amount'], count(model_state($rt->all()[0])['fillable']));

// Mutating the decoded copy's attributes must not bleed into original.
$m = phpser_unserialize(phpser_serialize($single));
(function () { $this->attributes['status'] = 'changed'; })->call($m);
var_dump($m->getAttributes()['status'], $m->getOriginal()['status']);

$signed = phpser_unserialize_signed(phpser_serialize_signed($coll, 'k'), 'k');
echo "signed serialize_equal: ", var_export(serialize($signed) === serialize($coll), true), "\n";
?>
--EXPECT--
model/fresh serialize_equal: true
model/materialized serialize_equal: true
collection/fresh serialize_equal: true
collection/materialized serialize_equal: true
collection per-model state: true
int(18)
string(8) "approved"
string(10) "transcript"
string(6) "149.07"
int(21397)
NULL
string(10) "scan_7.pdf"
string(9) "decimal:2"
int(16)
string(7) "changed"
string(7) "pending"
signed serialize_equal: true
