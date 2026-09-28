--TEST--
phpser: SerializesModels-style __serialize with mangled "\0*\0" / "\0Class\0" keys
--EXTENSIONS--
phpser
--FILE--
<?php
require __DIR__ . '/145-app-fixtures.inc';

// Same key scheme as Illuminate\Queue\SerializesModels: protected members are
// keyed "\0*\0name", private ones "\0Declaring\0name", public ones plain.
trait SerializesModelsLike {
    public function __serialize(): array {
        $values = [];
        foreach ((new ReflectionClass($this))->getProperties() as $property) {
            if ($property->isStatic() || !$property->isInitialized($this)) continue;
            $name = $property->getName();
            if ($property->isPrivate()) {
                $name = "\0{$property->getDeclaringClass()->getName()}\0{$name}";
            } elseif ($property->isProtected()) {
                $name = "\0*\0{$name}";
            }
            $values[$name] = $property->getValue($this);
        }
        return $values;
    }

    public function __unserialize(array $values): void {
        foreach ((new ReflectionClass($this))->getProperties() as $property) {
            if ($property->isStatic()) continue;
            $name = $property->getName();
            if ($property->isPrivate()) {
                $name = "\0{$property->getDeclaringClass()->getName()}\0{$name}";
            } elseif ($property->isProtected()) {
                $name = "\0*\0{$name}";
            }
            if (!array_key_exists($name, $values)) continue;
            $property->setValue($this, $values[$name]);
        }
    }
}

class SendDocumentJob {
    use SerializesModelsLike;

    public $connection = null;
    public $queue = 'default';
    public $middleware = [];
    protected $model;
    protected array $recipients;
    private string $token;
    private readonly int $attempt;

    public function __construct(EloquentModelLike $model, array $recipients, string $token) {
        $this->model = $model;
        $this->recipients = $recipients;
        $this->token = $token;
        $this->attempt = 2;
    }

    public function state(): array {
        return [$this->model, $this->recipients, $this->token, $this->attempt];
    }
}

$job = new SendDocumentJob(mk_eloquent_model(4), ['hr@example.com', 'ops@example.com'], fx_hex('tok', 32));

$keys = array_keys($job->__serialize());
echo implode(',', array_map(fn($k) => str_replace("\0", '\0', $k), $keys)), "\n";

foreach (['unsigned', 'signed'] as $mode) {
    $rt = $mode === 'signed'
        ? phpser_unserialize_signed(phpser_serialize_signed($job, 'k'), 'k')
        : phpser_unserialize(phpser_serialize($job));
    [$model, $recipients, $token, $attempt] = $rt->state();
    echo "$mode: ", get_class($rt), ' ', get_class($model), ' ', count($model->getAttributes()), ' ',
        implode('|', $recipients), ' ', strlen($token), ' ', $attempt, ' ', $rt->queue, "\n";
    echo "$mode serialize_equal: ", var_export(serialize($rt) === serialize($job), true), "\n";
}

// The raw mangled-key array itself (no object) must keep the NUL bytes.
$raw = $job->__serialize();
$rt = phpser_unserialize(phpser_serialize($raw));
var_dump(array_keys($rt) === array_keys($raw), $rt["\0SendDocumentJob\0attempt"]);

// A mangled key the class does not declare is ignored by __unserialize, not
// installed as a stray property.
$extra = phpser_serialize($raw + ["\0*\0removedLater" => 'x']);
$obj = (new ReflectionClass(SendDocumentJob::class))->newInstanceWithoutConstructor();
$obj->__unserialize(phpser_unserialize($extra));
var_dump(property_exists($obj, 'removedLater'), $obj->state()[3]);
?>
--EXPECT--
connection,queue,middleware,\0*\0model,\0*\0recipients,\0SendDocumentJob\0token,\0SendDocumentJob\0attempt
unsigned: SendDocumentJob EloquentModelLike 18 hr@example.com|ops@example.com 32 2 default
unsigned serialize_equal: true
signed: SendDocumentJob EloquentModelLike 18 hr@example.com|ops@example.com 32 2 default
signed serialize_equal: true
bool(true)
int(2)
bool(false)
int(2)
