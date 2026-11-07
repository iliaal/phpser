--TEST--
phpser: retained GC benchmarks reject invalid encoded payloads before decoding
--EXTENSIONS--
phpser
--FILE--
<?php
putenv('BENCH_ITERS=1');
putenv('BENCH_REPS=1');
putenv('BENCH_WARMUP=0');
$argv = [dirname(__DIR__) . '/bench.php', '--format=json'];
ob_start();
require $argv[0];
ob_end_clean();

foreach (['empty' => '', 'false' => false, 'null' => null,
          'integer' => 42, 'array' => [], 'object' => new stdClass()] as $label => $payload) {
    $decodes = 0;
    $serializers = [
        'bad' => [fn($value) => $payload, function ($blob) use (&$decodes) {
            ++$decodes;
            return [42];
        }],
        'good' => ['serialize', 'unserialize'],
    ];
    $results = bench_gc_retained(['value' => [42]], $serializers, 10, 1048576, 1);
    echo $label, "\n";
    var_dump($results['value']['bad'] === ['err' => 'empty payload']);
    var_dump($decodes === 0);
    $good = $results['value']['good'];
    var_dump(!isset($good['err']) && $good['keep'] === 10
        && is_float($good['gc_on']) && is_float($good['gc_off']));
}
?>
--EXPECT--
empty
bool(true)
bool(true)
bool(true)
false
bool(true)
bool(true)
bool(true)
null
bool(true)
bool(true)
bool(true)
integer
bool(true)
bool(true)
bool(true)
array
bool(true)
bool(true)
bool(true)
object
bool(true)
bool(true)
bool(true)
