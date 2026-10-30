--TEST--
phpser: signing keys are redacted from exception traces
--EXTENSIONS--
phpser
--INI--
zend.exception_ignore_args=0
--FILE--
<?php
$key = str_repeat('K', 32);
try {
    phpser_unserialize_signed(str_repeat('x', 40), $key);
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
    var_dump($e->getTrace()[0]['args'][1]);
}
class Thrower {
    public function __serialize(): array { throw new Exception('hook'); }
}
try {
    phpser_serialize_signed(new Thrower(), $key);
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
    foreach ($e->getTrace() as $frame) {
        if ($frame['function'] === 'phpser_serialize_signed') {
            var_dump($frame['args'][1]);
        }
    }
}
?>
--EXPECTF--
phpser: signature verification failed
object(SensitiveParameterValue)#%d (0) {
}
hook
object(SensitiveParameterValue)#%d (0) {
}
