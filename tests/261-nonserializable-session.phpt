--TEST--
phpser: non-serializable objects never publish partial plain or signed session frames
--EXTENSIONS--
phpser
session
--SKIPIF--
<?php
if (@ini_set('session.serialize_handler', 'phpser') === false) {
    die('skip phpser built without session support');
}
if (!function_exists('shell_exec')) die('skip shell_exec disabled');
?>
--INI--
session.serialize_handler=phpser
session.save_handler=files
session.use_cookies=0
session.use_strict_mode=0
session.cache_limiter=
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpser-261-' . md5(__DIR__);
@mkdir($dir);
function request261(string $code, string $key): string {
    global $dir;
    $php = getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY;
    $extra = getenv('TEST_PHP_EXTRA_ARGS') ?: '';
    $script = "$dir/child.php";
    // Files avoid Windows escapeshellarg() stripping quotes from -r code.
    file_put_contents($script, "<?php\nsession_save_path(" . var_export($dir, true) . ");\n" . $code);
    $cmd = escapeshellarg($php) . " $extra";
    foreach ([
        'session.serialize_handler' => 'phpser', 'session.save_handler' => 'files',
        'session.use_cookies' => '0', 'session.use_strict_mode' => '0',
        'session.cache_limiter' => '', 'display_errors' => '1',
        'phpser.session_key' => $key,
    ] as $k => $v) $cmd .= ' -d ' . escapeshellarg("$k=$v");
    return (string) shell_exec($cmd . ' ' . escapeshellarg($script) . ' 2>&1');
}
foreach (['plain' => '', 'signed' => str_repeat('k', 32)] as $mode => $key) {
    foreach (['explicit', 'shutdown'] as $save) {
        $id = $mode . $save;
        $start = 'session_id("' . $id . '"); session_start();';
        request261($start . '$_SESSION = ["keep" => 42]; session_write_close();', $key);
        $path = "$dir/sess_$id";
        $before = file_get_contents($path);
        $code = $start . '$_SESSION = ["before" => "AAA", "bad" => function () {}, "after" => "BBB"];';
        if ($save === 'explicit') {
            $code .= 'try { session_write_close(); echo "NO-THROW"; } catch (Throwable $e) { echo "CAUGHT:", $e->getMessage(); }';
        }
        $out = request261($code, $key);
        $message = "Serialization of 'Closure' is not allowed";
        $threw = str_contains($out, $message)
            && ($save === 'shutdown'
                ? str_contains($out, 'Uncaught Exception')
                : str_contains($out, 'an exception was thrown during serialization')
                    && str_contains($out, 'CAUGHT:' . $message));
        echo "$mode $save exception: ", $threw ? "OK" : $out, "\n";
        // Returning a partial frame while an exception is pending still throws
        // in userland. Inspect storage to catch that distinct encoder bug.
        // Explicit close on PHP <8.6 writes an empty string on NULL; newer
        // PHP skips the write. At shutdown an uncaught exception can end
        // the request before the write. Neither path may publish a frame.
        $after = file_get_contents($path);
        $expected = PHP_VERSION_ID >= 80600 ? $before : '';
        $noFrame = $save === 'shutdown' ? ($after === '' || $after === $before) : $after === $expected;
        echo "$mode $save no_partial_frame: ", $noFrame ? "OK" : bin2hex($after), "\n";
    }
}
?>
--CLEAN--
<?php
$dir = sys_get_temp_dir() . '/phpser-261-' . md5(__DIR__);
foreach (glob("$dir/*") as $f) @unlink($f);
@rmdir($dir);
?>
--EXPECT--
plain explicit exception: OK
plain explicit no_partial_frame: OK
plain shutdown exception: OK
plain shutdown no_partial_frame: OK
signed explicit exception: OK
signed explicit no_partial_frame: OK
signed shutdown exception: OK
signed shutdown no_partial_frame: OK
