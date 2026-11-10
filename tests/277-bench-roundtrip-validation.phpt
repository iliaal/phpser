--TEST--
phpser: benchmark cells reject incorrect decoded values before timing
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

$cases = [
    'false' => [42, false],
    'null' => [42, null],
    'type' => [42, '42'],
    'value' => [['id' => 42], ['id' => 43]],
    'keys' => [['id' => 42], ['other' => 42]],
    'class' => [(object) ['id' => 42], ['id' => 42]],
];
foreach (['matrix', 'retained'] as $path) {
    foreach ($cases as $label => [$value, $wrong]) {
        $calls = ['enc' => 0, 'dec' => 0];
        $serializers = [
            'bad' => [function ($v) use (&$calls) {
                ++$calls['enc'];
                return serialize($v);
            }, function ($blob) use (&$calls, $wrong) {
                ++$calls['dec'];
                return $wrong;
            }],
            'good' => ['serialize', 'unserialize'],
        ];
        $fixture = fn() => is_object($value) ? clone $value : $value;
        $results = $path === 'matrix'
            ? bench_matrix(['value' => $fixture], $serializers, 2, 2, 1)
            : bench_gc_retained(['value' => $fixture], $serializers, 10, 1048576, 1);
        $good = $results['value']['good'];
        echo "$path $label: ";
        var_dump($results['value']['bad'] === ['err' => 'round-trip mismatch']
            && $calls === ['enc' => 1, 'dec' => 1]
            && !isset($good['err'])
            && is_float($good[$path === 'matrix' ? 'dec' : 'gc_on']));
    }
    // A false or null result is valid when it matches the original value.
    // Builders also exercise objects without reusing another cell's instance.
    foreach (['false' => false, 'null' => null, 'zero' => 0, 'empty' => [],
              'object' => fn() => (object) ['id' => 42]] as $label => $value) {
        $serializers = ['native' => ['serialize', 'unserialize'],
            'phpser' => ['phpser_serialize', 'phpser_unserialize']];
        $results = $path === 'matrix'
            ? bench_matrix(['value' => $value], $serializers, 1, 1, 0)
            : bench_gc_retained(['value' => $value], $serializers, 10, 1048576, 1);
        echo "$path valid $label: ";
        var_dump(!isset($results['value']['native']['err'])
            && !isset($results['value']['phpser']['err']));
    }
}
// An invalid large probe must neither reduce healthy cells' retained count
// nor stay reachable during their samples.
$badProbe = null;
$results = bench_gc_retained(['value' => [42]], [
    'bad' => ['serialize', function ($blob) use (&$badProbe) {
        $probe = (object) ['data' => str_repeat('x', 2 * 1048576)];
        $badProbe = WeakReference::create($probe);
        return $probe;
    }],
    'good' => ['serialize', 'unserialize'],
], 100, 1048576, 1);
echo 'retained invalid probe: ';
var_dump($results['value']['bad'] === ['err' => 'round-trip mismatch']
    && $results['value']['good']['keep'] === 100
    && $badProbe->get() === null);
?>
--EXPECT--
matrix false: bool(true)
matrix null: bool(true)
matrix type: bool(true)
matrix value: bool(true)
matrix keys: bool(true)
matrix class: bool(true)
matrix valid false: bool(true)
matrix valid null: bool(true)
matrix valid zero: bool(true)
matrix valid empty: bool(true)
matrix valid object: bool(true)
retained false: bool(true)
retained null: bool(true)
retained type: bool(true)
retained value: bool(true)
retained keys: bool(true)
retained class: bool(true)
retained valid false: bool(true)
retained valid null: bool(true)
retained valid zero: bool(true)
retained valid empty: bool(true)
retained valid object: bool(true)
retained invalid probe: bool(true)
