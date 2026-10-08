<?php

declare(strict_types=1);

namespace App\Models\AuditLogs;

use App\Platform\AuditLogs\AuditLogChains;
use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One audit event an app sent about one of its customers — "Ada exported the Q3 invoices"
 * — kept in that organization's hash chain ({@see AuditLogChains}).
 *
 * ENVIRONMENT-OWNED, like everything a tenant's app writes here: the hard scope is what
 * keeps one environment's customers out of another's, and the organization is a predicate
 * in every query that reads them. Never updated after it is written — a change would break
 * the chain, which is the point of having one.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $organization_id
 * @property int $sequence
 * @property string $action
 * @property int|null $schema_version
 * @property string $occurred_at As stored: `Y-m-d H:i:s.v`, UTC.
 * @property string $actor_id
 * @property string $actor_type
 * @property string|null $actor_name
 * @property array<string, mixed>|null $actor_metadata
 * @property list<array<string, mixed>> $targets
 * @property string|null $location
 * @property string|null $user_agent
 * @property array<string, mixed>|null $metadata
 * @property string $prev_hash
 * @property string $hash
 * @property Carbon $created_at
 */
final class AuditLogEvent extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    /** How `occurred_at` is written and compared: to the millisecond, so a cursor is exact. */
    public const string TIME_FORMAT = 'Y-m-d H:i:s.v';

    public const UPDATED_AT = null;

    protected $table = 'app_audit_events';

    protected $guarded = [];

    /** A moment, in the form `occurred_at` is stored and compared in. */
    public static function storedTime(DateTimeInterface $moment): string
    {
        return Carbon::instance($moment)->utc()->format(self::TIME_FORMAT);
    }

    /** When it happened, as an ISO 8601 instant with milliseconds. */
    public function occurredAtIso(): string
    {
        return Carbon::parse($this->occurred_at, 'UTC')->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'schema_version' => 'integer',
            'actor_metadata' => 'array',
            'targets' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
