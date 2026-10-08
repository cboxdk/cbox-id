<?php

declare(strict_types=1);

namespace App\Models\AuditLogs;

use App\Platform\AuditLogs\Jobs\GenerateAuditLogExport;
use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A CSV export of audit events, written on the queue ({@see GenerateAuditLogExport}) and
 * handed out through a signed, short-lived URL once it is `ready`.
 *
 * @property string $id
 * @property string $environment_id
 * @property string|null $organization_id
 * @property array<string, mixed> $filters
 * @property string $state `pending`, `ready`, `failed` or `expired`
 * @property int|null $row_count
 * @property string|null $path
 * @property string|null $error
 * @property string $requested_by
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 */
final class AuditLogExport extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    public const string PENDING = 'pending';

    public const string READY = 'ready';

    public const string FAILED = 'failed';

    public const string EXPIRED = 'expired';

    protected $table = 'app_audit_exports';

    protected $guarded = [];

    /** Whether the file is there to be downloaded right now. */
    public function downloadable(): bool
    {
        return $this->state === self::READY
            && $this->path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** The state as a reader should see it: a ready export past its expiry has expired. */
    public function visibleState(): string
    {
        return $this->state === self::READY && ! $this->downloadable() ? self::EXPIRED : $this->state;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'row_count' => 'integer',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
