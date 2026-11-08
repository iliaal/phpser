--TEST--
phpser: forked benchmark samples reject results from failed child shutdowns
--EXTENSIONS--
phpser
pcntl
posix
--FILE--
<?php
putenv('BENCH_ITERS=1');
putenv('BENCH_REPS=1');
putenv('BENCH_WARMUP=0');
$argv = [dirname(__DIR__) . '/bench.php', '--format=json'];
ob_start();
require $argv[0];
ob_end_clean();

$shutdowns = [
    'exit' => function (): void { exit(7); },
    'signal' => function (): void {
        pcntl_signal(SIGTERM, SIG_DFL);
        posix_kill(getmypid(), SIGTERM);
    },
];
foreach ($shutdowns as $name => $shutdown) {
    echo $name, "\n";
    try {
        run_isolated(function () use ($shutdown): array {
            // run_isolated writes this valid result before shutdown runs.
            register_shutdown_function($shutdown);
            return ['value' => 42];
        });
        echo "accepted failed child\n";
    } catch (RuntimeException $e) {
        echo $e->getMessage(), "\n";
    }
    var_dump(pcntl_waitpid(-1, $status, WNOHANG) === -1);

    $parent = getmypid();
    $registered = false;
    $bad = function ($blob) use ($parent, $shutdown, &$registered) {
        if (getmypid() !== $parent && !$registered) {
            register_shutdown_function($shutdown);
            $registered = true;
        }
        return unserialize($blob);
    };
    $results = bench_gc_retained(
        ['first' => [42], 'second' => [43]],
        ['bad' => ['serialize', $bad], 'good' => ['serialize', 'unserialize']],
        10, 1048576, 2);
    foreach ($results as $row) {
        var_dump($row['bad'] === ['err' => 'forked sample exited unsuccessfully']);
        var_dump(!isset($row['good']['err']) && $row['good']['isolated']
            && $row['good']['keep'] === 10
            && is_float($row['good']['gc_on']) && is_float($row['good']['gc_off']));
    }
}
var_dump(run_isolated(function (): array {
    register_shutdown_function(function (): void { exit(0); });
    return ['normal' => true];
}));
?>
--EXPECT--
exit
forked sample exited unsuccessfully
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
signal
forked sample exited unsuccessfully
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
array(2) {
  ["normal"]=>
  bool(true)
  ["isolated"]=>
  bool(true)
}
