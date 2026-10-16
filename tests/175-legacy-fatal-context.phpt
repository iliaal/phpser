--TEST--
phpser: fatal legacy hooks restore native decoding policy for shutdown callbacks
--EXTENSIONS--
phpser
--INI--
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
class FatalLegacy implements Serializable {
    public function serialize(): string { return ''; }
    public function unserialize(string $data): void {
        trigger_error('legacy fatal', E_USER_ERROR);
    }
}
register_shutdown_function(function () {
    var_dump(get_class(unserialize('O:8:"stdClass":0:{}')));
    $object = new stdClass();
    $copy = unserialize(serialize([$object, $object]));
    var_dump(is_array($copy) && $copy[0] === $copy[1]);
});
phpser_unserialize("\x01\x01\x0bFatalLegacy\x0f\x00\x00", [
    'allowed_classes' => [FatalLegacy::class],
]);
?>
--EXPECTF--
Fatal error: legacy fatal in %s on line %d
string(8) "stdClass"
bool(true)
