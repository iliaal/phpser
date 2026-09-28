--TEST--
phpser: exact-size frame assembly keeps wire bytes and lays the HMAC tag after the frame
--SKIPIF--
<?php
if (!extension_loaded('phpser')) die('skip phpser not loaded');
?>
--FILE--
<?php
class P { public $a = 1; public $b = 'xx'; }

// Frame bytes are pinned: header (version, dict), then body. The v2 header on
// 'obj' comes from the OBJECT_SLOTS tag; 'dictarr' and 'rows' carry
// multi-entry dictionaries so the header length is computed, not constant.
$cases = [
    'null'    => [null, '010000'],
    'int'     => [42, '01000354'],
    'float'   => [1.5, '010004000000000000f83f'],
    'str'     => ['hello world abc', '01000c0f68656c6c6f20776f726c6420616263'],
    'list'    => [[1, 2, 3], '01000803020406'],
    'assoc'   => [['a' => 1, 'b' => [1, 2, 3]], '0100060202016103020201620803020406'],
    'dictarr' => [[['k' => 'v1'], ['k' => 'v2'], ['k' => 'v1']], '0203016b027631027632150301000b010201'],
    'obj'     => [new P, '0201015012000203020c027878'],
    'rows'    => [array_map(fn($i) => ['id' => $i, 'name' => 'n' . ($i % 2)], range(0, 4)),
                  '0204026964046e616d65026e30026e3115050200010800020406080b0203020302'],
];
$key = 'shared-secret-key';
foreach ($cases as $name => [$value, $hex]) {
    $frame = phpser_serialize($value);
    $signed = phpser_serialize_signed($value, $key);
    $tag = hash_hmac('sha256', $frame, $key, true);
    printf("%-8s bytes:%s tail:%s len:%s roundtrip:%s\n",
        $name,
        bin2hex($frame) === $hex ? 'ok' : 'FAIL ' . bin2hex($frame),
        $signed === $frame . $tag ? 'ok' : 'FAIL',
        strlen($signed) === strlen($frame) + 32 ? 'ok' : 'FAIL',
        phpser_unserialize_signed($signed, $key, ['allowed_classes' => true]) == $value ? 'ok' : 'FAIL');
}

// A frame with a large body and a dictionary: the tag still covers exactly the
// frame, and any flipped byte anywhere is rejected.
$big = array_map(fn($i) => ['id' => $i, 'name' => 'user_' . $i, 'tags' => ['a', 'b']], range(0, 999));
$frame = phpser_serialize($big);
$signed = phpser_serialize_signed($big, $key);
echo $signed === $frame . hash_hmac('sha256', $frame, $key, true) ? "big tail ok\n" : "big tail FAIL\n";
echo phpser_unserialize_signed($signed, $key) === $big ? "big roundtrip ok\n" : "big roundtrip FAIL\n";
$flips = [
    'version'    => 0,
    'middle'     => intdiv(strlen($frame), 2),
    'frame-last' => strlen($frame) - 1,
    'tag-first'  => strlen($frame),
    'tag-last'   => strlen($signed) - 1,
];
foreach ($flips as $label => $off) {
    $bad = $signed;
    $bad[$off] = chr(ord($bad[$off]) ^ 1);
    try {
        phpser_unserialize_signed($bad, $key);
        echo "flip $label FAIL (accepted)\n";
    } catch (Exception $e) {
        echo "flip $label rejected\n";
    }
}

// Signed output is an ordinary string: it survives copy-on-write and
// concatenation with its length intact.
$copy = $signed;
$copy .= 'x';
echo strlen($signed) === strlen($frame) + 32 && strlen($copy) === strlen($signed) + 1 ? "cow ok\n" : "cow FAIL\n";
?>
--EXPECT--
null     bytes:ok tail:ok len:ok roundtrip:ok
int      bytes:ok tail:ok len:ok roundtrip:ok
float    bytes:ok tail:ok len:ok roundtrip:ok
str      bytes:ok tail:ok len:ok roundtrip:ok
list     bytes:ok tail:ok len:ok roundtrip:ok
assoc    bytes:ok tail:ok len:ok roundtrip:ok
dictarr  bytes:ok tail:ok len:ok roundtrip:ok
obj      bytes:ok tail:ok len:ok roundtrip:ok
rows     bytes:ok tail:ok len:ok roundtrip:ok
big tail ok
big roundtrip ok
flip version rejected
flip middle rejected
flip frame-last rejected
flip tag-first rejected
flip tag-last rejected
cow ok
