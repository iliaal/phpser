--TEST--
phpser: queue-job-like object with a private readonly promoted DTO property
--EXTENSIONS--
phpser
--FILE--
<?php
require __DIR__ . '/145-app-fixtures.inc';

$job = mk_queue_job();
$native_keys = array_keys((array) $job);
echo str_replace("\0", '\0', $native_keys[count($native_keys) - 1]), "\n";

foreach (['unsigned', 'signed'] as $mode) {
    $rt = $mode === 'signed'
        ? phpser_unserialize_signed(phpser_serialize_signed($job, 'k'), 'k')
        : phpser_unserialize(phpser_serialize($job));
    echo "$mode serialize_equal: ", var_export(serialize($rt) === serialize($job), true), "\n";
    $dto = $rt->dto();
    var_dump($dto->event, strlen($dto->userAgent), $dto->occurredAt->date, count($dto->context), count($dto->changes), $dto->severity);
    var_dump($rt->connection, $rt->middleware, $rt->chained);

    // The private readonly property stays readonly after decode.
    try {
        (function () { $this->dto = mk_queue_job()->dto(); })->call($rt);
        echo "readonly reassigned\n";
    } catch (Error $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
    $rp = new ReflectionProperty(QueueJobLike::class, 'dto');
    var_dump($rp->isInitialized($rt), $rp->isReadOnly());
}

// A batch of jobs sharing one DTO keeps the shared identity.
$shared = mk_queue_job()->dto();
$batch = [new QueueJobLike($shared), new QueueJobLike($shared)];
$rt = phpser_unserialize(phpser_serialize($batch));
var_dump($rt[0]->dto() === $rt[1]->dto(), $rt[0] !== $rt[1]);
?>
--EXPECT--
\0QueueJobLike\0dto
unsigned serialize_equal: true
enum(AuditEvent::Shared)
int(117)
string(26) "2026-01-01 02:00:00.016800"
int(5)
int(3)
int(2)
NULL
array(0) {
}
array(0) {
}
Error: Cannot modify readonly property QueueJobLike::$dto
bool(true)
bool(true)
signed serialize_equal: true
enum(AuditEvent::Shared)
int(117)
string(26) "2026-01-01 02:00:00.016800"
int(5)
int(3)
int(2)
NULL
array(0) {
}
array(0) {
}
Error: Cannot modify readonly property QueueJobLike::$dto
bool(true)
bool(true)
bool(true)
bool(true)
