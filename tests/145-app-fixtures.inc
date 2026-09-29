<?php
// Shared by tests/145-154: copies of the bench.php --extended app fixture
// builders (Eloquent model/collection, Carbon, wallet, queue job, catalog,
// JWKS). Keep in step with bench.php when the shapes change.

function mk_rowset(int $rows): array {
    $out = [];
    for ($i = 0; $i < $rows; $i++) {
        $out[] = [
            'id' => $i,
            'user_id' => 1000 + ($i % 50),
            'name' => 'row_' . $i,
            'created_at' => '2026-05-19T12:00:00Z',
            'amount' => $i * 1.07,
            'active' => ($i % 3) === 0,
            'tags' => ['a', 'b', 'c'],
        ];
    }
    return $out;
}

function distinct_string(string $value): string {
    return substr($value . "\0", 0, strlen($value));
}

// Rebuilds a list element by element so the result is a refcounted heap array
// rather than the immutable literal (array_values() would return the input).
function distinct_list(array $src): array {
    $out = [];
    foreach ($src as $v) $out[] = $v;
    return $out;
}

function mk_rowset_tags_distinct(int $rows): array {
    $out = mk_rowset($rows);
    foreach ($out as $i => $row) $out[$i]['tags'] = distinct_list($row['tags']);
    return $out;
}

function mk_rowset_tags_shared(int $rows): array {
    $out = mk_rowset($rows);
    $shared = distinct_list(['a', 'b', 'c']);
    foreach ($out as $i => $row) $out[$i]['tags'] = $shared;
    return $out;
}

function mk_small_assoc(): array {
    return [
        'id' => 42, 'name' => distinct_string('alice'), 'email' => distinct_string('alice@example.com'),
        'active' => true, 'score' => 1.5, 'roles' => distinct_list(['admin', 'editor']),
    ];
}

function fx_hex(string $seed, int $len): string {
    return substr(hash('sha256', $seed), 0, $len);
}

function fx_uuid7(string $seed): string {
    $h = hash('sha256', $seed);
    return sprintf('%s-%s-7%s-%s-%s',
        substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 16, 4), substr($h, 20, 12));
}

function fx_ts(int $offset): string {
    return gmdate('Y-m-d H:i:s', 1767225600 + $offset);
}

// Mirrors the property surface of an Illuminate model instance as it sits in
// cache: framework defaults (mostly empty arrays / null / false), class-level
// casts/fillable/guarded literals, and attributes === original after a fetch.
class EloquentModelLike {
    protected $connection = 'mysql';
    protected $table = null;
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $with = [];
    protected $withCount = [];
    public $preventsLazyLoading = false;
    protected $perPage = 15;
    public $exists = false;
    public $wasRecentlyCreated = false;
    protected $escapeWhenCastingToString = false;
    protected $attributes = [];
    protected $original = [];
    protected $changes = [];
    protected $previous = [];
    protected $casts = [
        'id' => 'string', 'metadata' => 'array', 'amount' => 'decimal:2',
        'size' => 'integer', 'version' => 'integer', 'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    protected $classCastCache = [];
    protected $attributeCastCache = [];
    protected $dateFormat = null;
    protected $appends = [];
    protected $dispatchesEvents = [];
    protected $observables = [];
    protected $relations = [];
    protected $touches = [];
    protected $relationAutoloadCallback = null;
    protected $relationAutoloadContext = null;
    public $timestamps = true;
    public $usesUniqueIds = true;
    protected $hidden = [];
    protected $visible = [];
    protected $fillable = [
        'owner_id', 'hash', 'path', 'thumbnail_path', 'original_path', 'metadata',
        'status', 'type', 'amount', 'size', 'version', 'deleted_at', 'expires_at',
        'rejected_reason', 'notes', 'verified_by',
    ];
    protected $guarded = ['id', 'created_at', 'updated_at'];
    protected $forceDeleting = false;
    protected $dates = [];
    protected $hashAlgo = null;
    protected $pivotParent = null;
    protected $morphClass = null;
    protected $lockVersion = null;
    protected $readOnly = false;
    protected $unguarded = false;
    protected $routeKeyName = null;

    public function setRawAttributes(array $attributes): void {
        $this->attributes = $attributes;
        $this->original = $this->attributes;
        $this->exists = true;
    }

    public function getAttributes(): array { return $this->attributes; }
    public function getOriginal(): array { return $this->original; }
}

function mk_eloquent_model(int $i): EloquentModelLike {
    $statuses = ['pending', 'approved', 'rejected'];
    $types = ['license', 'passport', 'certificate', 'transcript'];
    $id = fx_uuid7("doc$i");
    $owner = fx_uuid7('owner' . ($i % 7));
    $m = new EloquentModelLike();
    $m->setRawAttributes([
        'id' => $id,
        'owner_id' => $owner,
        'hash' => hash('sha256', "content$i"),
        'path' => "documents/$owner/$id.pdf",
        'thumbnail_path' => "thumbnails/$owner/$id.png",
        'original_path' => "uploads/tmp/" . fx_hex("up$i", 20) . ".pdf",
        'metadata' => json_encode([
            'mime' => 'application/pdf', 'pages' => 1 + $i % 9,
            'original_name' => "scan_$i.pdf", 'bytes' => 20480 + $i * 131,
        ]),
        'status' => distinct_string($statuses[$i % 3]),
        'type' => distinct_string($types[$i % 4]),
        'created_at' => fx_ts($i * 3607),
        'updated_at' => fx_ts($i * 3607 + 86400),
        'amount' => sprintf('%d.%02d', 100 + $i * 7, $i % 100),
        'size' => 20480 + $i * 131,
        'version' => 1 + $i % 3,
        'deleted_at' => null,
        'expires_at' => null,
        'rejected_reason' => null,
        'notes' => null,
    ]);
    return $m;
}

// Illuminate\Support\Collection keeps its models in a protected $items list.
class CollectionLike {
    protected $items = [];
    protected $escapeWhenCastingToString = false;
    public function __construct(array $items) { $this->items = $items; }
    public function all(): array { return $this->items; }
}

function mk_eloquent_collection(int $n): CollectionLike {
    $items = [];
    for ($i = 0; $i < $n; $i++) $items[] = mk_eloquent_model($i);
    return new CollectionLike($items);
}

// Carbon's own __serialize() payload shape (DateTime-compatible triple).
final class CarbonLike {
    public string $date;
    public int $timezone_type;
    public string $timezone;

    public function __construct(string $date) {
        $this->date = $date;
        $this->timezone_type = 3;
        $this->timezone = 'UTC';
    }

    public function __serialize(): array {
        return ['date' => $this->date, 'timezone_type' => $this->timezone_type, 'timezone' => $this->timezone];
    }

    public function __unserialize(array $data): void {
        $this->date = $data['date'];
        $this->timezone_type = $data['timezone_type'];
        $this->timezone = $data['timezone'];
    }
}

function mk_carbon(int $offset): CarbonLike {
    return new CarbonLike(fx_ts($offset) . sprintf('.%06d', ($offset * 7919) % 1000000));
}

enum WalletGroupType: string {
    case Licenses = 'licenses';
    case Certifications = 'certifications';
    case Education = 'education';
    case Identity = 'identity';
    case Employment = 'employment';
}

enum RecordStatus: string {
    case Active = 'active';
    case Expired = 'expired';
    case Pending = 'pending';
}

enum RecordSource: string {
    case Manual = 'manual';
    case PrimarySource = 'primary_source';
    case Import = 'import';
}

enum RecordVisibility: string {
    case Private = 'private';
    case Shared = 'shared';
}

enum RecordKind: int {
    case License = 1;
    case Certificate = 2;
    case Degree = 3;
    case Badge = 4;
}

final class VerificationSource {
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $url,
        public readonly RecordSource $kind,
        public readonly ?CarbonLike $checkedAt,
    ) {}
}

final class WalletRecord {
    public function __construct(
        public readonly string $id,
        public readonly string $walletId,
        public readonly RecordKind $kind,
        public readonly RecordStatus $status,
        public readonly RecordSource $source,
        public readonly RecordVisibility $visibility,
        public readonly ?CarbonLike $issuedAt,
        public readonly ?CarbonLike $expiresAt,
        public readonly ?CarbonLike $verifiedAt,
        public readonly array $details,
        public readonly string $title,
        public readonly string $issuer,
        public readonly string $number,
        public readonly string $country,
        public readonly ?string $state,
        public readonly ?string $notes,
        public readonly bool $isPrimary,
        public readonly bool $isVerified,
        public readonly bool $isExpired,
        public readonly int $sortOrder,
        public readonly int $documentCount,
        public readonly array $documents,
        public readonly ?VerificationSource $verificationSource,
        public readonly array $metadata,
        public readonly array $tags,
        public readonly string $createdBy,
        public readonly ?float $score,
    ) {}
}

final class WalletGroup {
    public function __construct(
        public readonly WalletGroupType $type,
        public readonly array $records,
        public readonly array $documents,
    ) {}
}
// Records alternate $docsPerRecord / $docsPerRecord-1 documents; the defaults
// (10 groups x 4 records, 100 models) put the native serialize() payload at
// ~390 KB, the size class of the ~372 KB production sample. Every model is referenced twice: from its
// record and from the group's flat document list.
function mk_wallet(int $groups = 10, int $recordsPerGroup = 4, int $docsPerRecord = 3): array {
    $types = WalletGroupType::cases();
    $statuses = RecordStatus::cases();
    $kinds = RecordKind::cases();
    $walletId = fx_uuid7('wallet');
    $source = new VerificationSource(
        fx_uuid7('vsrc'), 'State Licensing Board', 'https://verify.example.gov/lookup',
        RecordSource::PrimarySource, mk_carbon(999),
    );
    $out = [];
    $doc = 0;
    $rec = 0;
    for ($g = 0; $g < $groups; $g++) {
        $groupDocs = [];
        $records = [];
        for ($r = 0; $r < $recordsPerGroup; $r++, $rec++) {
            $recDocs = [];
            $docCount = $docsPerRecord - $rec % 2;
            for ($d = 0; $d < $docCount; $d++, $doc++) {
                $model = mk_eloquent_model($doc);
                $recDocs[] = $model;
                $groupDocs[] = $model;
            }
            $records[] = new WalletRecord(
                id: fx_uuid7("rec$rec"),
                walletId: $walletId,
                kind: $kinds[$rec % count($kinds)],
                status: $statuses[$rec % count($statuses)],
                source: $rec % 2 ? RecordSource::PrimarySource : RecordSource::Manual,
                visibility: $rec % 5 ? RecordVisibility::Shared : RecordVisibility::Private,
                issuedAt: mk_carbon($rec * 86400),
                expiresAt: $rec % 3 ? mk_carbon($rec * 86400 + 31536000) : null,
                verifiedAt: $rec % 2 ? mk_carbon($rec * 86400 + 3600) : null,
                details: [distinct_string('Board certified'), "Class " . chr(65 + $rec % 4), "Renewal " . (2027 + $rec % 3)],
                title: "Registered Nurse License $rec",
                issuer: 'State Board of Nursing',
                number: sprintf('RN-%07d', 40000 + $rec * 17),
                country: distinct_string('US'),
                state: $rec % 4 ? distinct_string('CA') : null,
                notes: $rec % 6 ? null : "Renewed after audit $rec",
                isPrimary: $r === 0,
                isVerified: $rec % 2 === 1,
                isExpired: false,
                sortOrder: $r,
                documentCount: $docCount,
                documents: $recDocs,
                verificationSource: $rec % 2 ? $source : null,
                metadata: ['imported' => false, 'rev' => $rec % 4, 'origin' => distinct_string('portal')],
                tags: [],
                createdBy: fx_uuid7('user' . ($rec % 3)),
                score: $rec % 3 ? 0.5 + ($rec % 10) / 20 : null,
            );
        }
        $out[] = new WalletGroup($types[$g % count($types)], $records, $groupDocs);
    }
    return $out;
}

enum AuditEvent: string {
    case Viewed = 'viewed';
    case Shared = 'shared';
    case Downloaded = 'downloaded';
}

final class AuditDto {
    public function __construct(
        public readonly AuditEvent $event,
        public readonly string $actorId,
        public readonly string $subjectId,
        public readonly string $tenantId,
        public readonly array $context,
        public readonly array $changes,
        public readonly string $userAgent,
        public readonly CarbonLike $occurredAt,
        public readonly string $ipAddress,
        public readonly string $url,
        public readonly string $method,
        public readonly string $requestId,
        public readonly int $severity,
    ) {}
}

// Queue payload shape: Queueable defaults around a private readonly DTO.
final class QueueJobLike {
    public $connection = null;
    public $queue = null;
    public $delay = null;
    public $afterCommit = null;
    public $middleware = [];
    public $chained = [];
    public $chainConnection = null;
    public $chainQueue = null;
    public $chainCatchCallbacks = null;
    public $job = null;

    public function __construct(private readonly AuditDto $dto) {}

    public function dto(): AuditDto { return $this->dto; }
}

function mk_queue_job(): QueueJobLike {
    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';
    return new QueueJobLike(new AuditDto(
        event: AuditEvent::Shared,
        actorId: fx_uuid7('actor'),
        subjectId: fx_uuid7('subject'),
        tenantId: fx_uuid7('tenant'),
        context: ['share_id' => fx_uuid7('share'), 'recipient' => 'hr@example.com', 'expires_in' => 604800, 'records' => 3, 'channel' => distinct_string('email')],
        changes: ['status' => distinct_string('shared'), 'shared_at' => fx_ts(7200), 'count' => 4],
        userAgent: distinct_string($ua),
        occurredAt: mk_carbon(7200),
        ipAddress: distinct_string('203.0.113.42'),
        url: 'https://app.example.com/wallet/share/' . fx_hex('share', 16),
        method: distinct_string('POST'),
        requestId: fx_uuid7('request'),
        severity: 2,
    ));
}

function mk_catalog(int $rows = 60): array {
    $kinds = ['date', 'string', 'email', 'numeric'];
    $out = [];
    for ($r = 0; $r < $rows; $r++) {
        $attrs = [];
        $n = 10 + ($r * 7) % 16;
        for ($a = 0; $a < $n; $a++) {
            $validators = [];
            $validators[] = 'nullable';
            $validators[] = $kinds[($r + $a) % 4];
            $attrs[] = [
                'label' => ucfirst(str_replace('_', ' ', "field_{$a}_label")),
                'name' => "field_$a",
                'validators' => $validators,
                'isHidden' => $a % 5 === 4,
            ];
        }
        $out[] = ['document_type' => "doc_type_$r", 'label' => "Document Type $r", 'attributes' => $attrs];
    }
    return $out;
}

function mk_jwks(): array {
    $keys = [];
    for ($k = 0; $k < 2; $k++) {
        $raw = '';
        for ($b = 0; strlen($raw) < 256; $b++) $raw .= hash('sha256', "modulus$k-$b", true);
        $keys[] = [
            'alg' => 'RS256',
            'e' => 'AQAB',
            'kid' => fx_hex("kid$k", 40),
            'kty' => 'RSA',
            'n' => rtrim(strtr(base64_encode(substr($raw, 0, 256)), '+/', '-_'), '='),
            'use' => 'sig',
        ];
    }
    return ['keys' => $keys];
}
