--TEST--
phpser: repeat identities do not grow a half-full encoder id table
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0') die('skip requires the Zend allocator');
$before = memory_get_usage();
$probe = [];
for ($i = 0; $i < 1000; $i++) $probe[] = str_repeat('x', 100);
if (memory_get_usage() - $before < 100000) die('skip request allocations are not tracked');
?>
--FILE--
<?php
function encode_peak(array $value): int {
    phpser_serialize($value);
    memory_reset_peak_usage();
    $start = memory_get_usage();
    $payload = phpser_serialize($value);
    return memory_get_peak_usage() - $start;
}

foreach (['objects', 'references', 'arrays'] as $kind) {
    $owners = [];
    // The scalar prevents the columnar rowset fast path from changing shape
    // when a repeated object is added. Only identity-table growth is measured.
    $input = [null];
    for ($i = 0; $i < 1024; $i++) {
        if ($kind === 'objects') {
            $owners[] = (object)['id' => $i];
            $input[] = $owners[$i];
        } elseif ($kind === 'references') {
            $owners[] = $i;
            $input[] =& $owners[$i];
        } else {
            $owners[] = [$i];
            $input[] = $owners[$i];
        }
    }
    $uniquePeak = encode_peak($input);
    if ($kind === 'references') $input[] =& $owners[0];
    else $input[] = $owners[0];
    $repeatPeak = encode_peak($input);
    // One TAG_REF needs only a few bytes. Allow a full smart_str page and
    // allocator-bin variation; an unnecessary 2048-to-4096-slot rehash costs
    // at least 32 KiB more even with 12-byte id_entry slots on 32-bit builds.
    echo $kind, ': ', $repeatPeak <= $uniquePeak + 16384 ? 'bounded' : 'grew', "\n";
}
?>
--EXPECT--
objects: bounded
references: bounded
arrays: bounded
