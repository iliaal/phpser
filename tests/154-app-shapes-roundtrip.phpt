--TEST--
phpser: catalog, JWKS, wrapped envelopes, and shared/distinct nested arrays round-trip
--EXTENSIONS--
phpser
--FILE--
<?php
require __DIR__ . '/145-app-fixtures.inc';

$shared_tags = mk_rowset_tags_shared(50);
$distinct_tags = mk_rowset_tags_distinct(50);
$cases = [
    'catalog' => mk_catalog(),
    'jwks' => mk_jwks(),
    'wrapped_rowset' => ['data' => mk_rowset(200), 'ttl' => 60],
    'wrapped_collection' => ['data' => mk_eloquent_collection(5), 'ttl' => 60],
    'rowset_tags_shared' => $shared_tags,
    'rowset_tags_distinct' => $distinct_tags,
    'small_assoc' => mk_small_assoc(),
    'carbon' => mk_carbon(5),
    'string_15' => distinct_string('cache:user:4217'),
];
foreach ($cases as $label => $value) {
    $u = phpser_unserialize(phpser_serialize($value));
    $s = phpser_unserialize_signed(phpser_serialize_signed($value, 'k'), 'k');
    printf("%s: %s %s\n", $label,
        var_export(serialize($u) === serialize($value), true),
        var_export(serialize($s) === serialize($value), true));
}

$jwks = phpser_unserialize(phpser_serialize($cases['jwks']));
var_dump(count($jwks['keys']), strlen($jwks['keys'][1]['n']), $jwks['keys'][0]['e'], $jwks['keys'][1]['use']);

$catalog = phpser_unserialize(phpser_serialize($cases['catalog']));
$attrs = 0;
foreach ($catalog as $row) $attrs += count($row['attributes']);
var_dump(count($catalog), $attrs, $catalog[3]['attributes'][4]['validators'], $catalog[3]['attributes'][4]['isHidden']);

// Arrays shared across rows are values: writing through one decoded row must
// not reach the others, whether or not the wire deduplicated them.
foreach (['rowset_tags_shared', 'rowset_tags_distinct'] as $label) {
    $rows = phpser_unserialize(phpser_serialize($cases[$label]));
    $rows[0]['tags'][] = 'z';
    $rows[1]['tags'][0] = 'q';
    echo "$label: ", implode(',', $rows[0]['tags']), ' | ', implode(',', $rows[1]['tags']), ' | ', implode(',', $rows[2]['tags']), "\n";
}
$catalog[0]['attributes'][0]['validators'][] = 'required';
var_dump($catalog[0]['attributes'][1]['validators'], $catalog[1]['attributes'][0]['validators']);
?>
--EXPECT--
catalog: true true
jwks: true true
wrapped_rowset: true true
wrapped_collection: true true
rowset_tags_shared: true true
rowset_tags_distinct: true true
small_assoc: true true
carbon: true true
string_15: true true
int(2)
int(342)
string(4) "AQAB"
string(3) "sig"
int(60)
int(1054)
array(2) {
  [0]=>
  string(8) "nullable"
  [1]=>
  string(7) "numeric"
}
bool(true)
rowset_tags_shared: a,b,c,z | q,b,c | a,b,c
rowset_tags_distinct: a,b,c,z | q,b,c | a,b,c
array(2) {
  [0]=>
  string(8) "nullable"
  [1]=>
  string(6) "string"
}
array(2) {
  [0]=>
  string(8) "nullable"
  [1]=>
  string(6) "string"
}
