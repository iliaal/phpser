--TEST--
phpser: Carbon-like __serialize objects in nested DTO graphs; shared identity across containers
--EXTENSIONS--
phpser
--FILE--
<?php
require __DIR__ . '/145-app-fixtures.inc';

$wallet = mk_wallet(3, 4, 3);
foreach (['unsigned', 'signed'] as $mode) {
    $rt = $mode === 'signed'
        ? phpser_unserialize_signed(phpser_serialize_signed($wallet, 'k'), 'k')
        : phpser_unserialize(phpser_serialize($wallet));
    echo "$mode serialize_equal: ", var_export(serialize($rt) === serialize($wallet), true), "\n";

    $shared_docs = true;
    $sources = [];
    foreach ($rt as $group) {
        $flat = [];
        foreach ($group->records as $record) {
            foreach ($record->documents as $doc) $flat[] = $doc;
            if ($record->verificationSource !== null) $sources[] = $record->verificationSource;
        }
        // Record documents and the group's flat list must be the same instances.
        foreach ($flat as $i => $doc) $shared_docs = $shared_docs && $doc === $group->documents[$i];
    }
    echo "$mode record/group documents identical: ", var_export($shared_docs, true), "\n";
    $one_source = count($sources) > 1;
    foreach ($sources as $s) $one_source = $one_source && $s === $sources[0];
    echo "$mode verification source shared: ", var_export($one_source, true), " (", count($sources), " refs)\n";

    $r = $rt[1]->records[2];
    var_dump($r->issuedAt instanceof CarbonLike, $r->issuedAt->date, $r->issuedAt->timezone_type, $r->issuedAt->timezone);
    var_dump($r->expiresAt?->date, $r->verifiedAt);
    var_dump($r->kind, $r->status, $r->source, $r->visibility, $rt[1]->type);
    var_dump($r->details);
    var_dump($sources[0]->checkedAt->date);

    // Same instance means a mutation through one container shows in the other.
    $doc = $rt[0]->records[0]->documents[1];
    (function () { $this->attributes['notes'] = 'edited'; })->call($doc);
    var_dump($rt[0]->documents[1]->getAttributes()['notes']);
}

// A Carbon value shared by two unrelated containers keeps one identity.
$when = mk_carbon(42);
$pair = ['a' => ['at' => $when], 'b' => (object) ['at' => $when], 'c' => [$when, $when]];
$rt = phpser_unserialize(phpser_serialize($pair));
var_dump($rt['a']['at'] === $rt['b']->at, $rt['c'][0] === $rt['c'][1], $rt['a']['at'] === $rt['c'][0]);
?>
--EXPECT--
unsigned serialize_equal: true
unsigned record/group documents identical: true
unsigned verification source shared: true (6 refs)
bool(true)
string(26) "2026-01-07 00:00:00.209600"
int(3)
string(3) "UTC"
NULL
NULL
enum(RecordKind::Degree)
enum(RecordStatus::Active)
enum(RecordSource::Manual)
enum(RecordVisibility::Shared)
enum(WalletGroupType::Certifications)
array(3) {
  [0]=>
  string(15) "Board certified"
  [1]=>
  string(7) "Class C"
  [2]=>
  string(12) "Renewal 2027"
}
string(26) "2026-01-01 00:16:39.911081"
string(6) "edited"
signed serialize_equal: true
signed record/group documents identical: true
signed verification source shared: true (6 refs)
bool(true)
string(26) "2026-01-07 00:00:00.209600"
int(3)
string(3) "UTC"
NULL
NULL
enum(RecordKind::Degree)
enum(RecordStatus::Active)
enum(RecordSource::Manual)
enum(RecordVisibility::Shared)
enum(WalletGroupType::Certifications)
array(3) {
  [0]=>
  string(15) "Board certified"
  [1]=>
  string(7) "Class C"
  [2]=>
  string(12) "Renewal 2027"
}
string(26) "2026-01-01 00:16:39.911081"
string(6) "edited"
bool(true)
bool(true)
bool(true)
