--TEST--
phpser: forked benchmark samples survive an interrupted wait
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

// Disable syscall restarting so the signal interrupts the parent's waitpid.
$signals = 0;
pcntl_async_signals(true);
pcntl_signal(SIGUSR1, function () use (&$signals): void {
    $signals++;
}, false);
$parent = getmypid();
$result = run_isolated(function () use ($parent): array {
    usleep(100000);
    posix_kill($parent, SIGUSR1);
    usleep(100000);
    return ['value' => 42];
});
var_dump($signals, $result);
// Normal and throwing samples must keep their existing result contracts.
var_dump(run_isolated(fn() => ['normal' => true]));
var_dump(run_isolated(function (): array {
    throw new RuntimeException('sample failed');
}));
// run_isolated must reap its child, not merely read its result file.
var_dump(pcntl_waitpid(-1, $status, WNOHANG));
?>
--EXPECT--
int(1)
array(2) {
  ["value"]=>
  int(42)
  ["isolated"]=>
  bool(true)
}
array(2) {
  ["normal"]=>
  bool(true)
  ["isolated"]=>
  bool(true)
}
array(2) {
  ["err"]=>
  string(13) "sample failed"
  ["isolated"]=>
  bool(true)
}
int(-1)
