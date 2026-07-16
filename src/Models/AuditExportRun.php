<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A record of one export run — the trail the console shows and the command prints.
 * `status` is `completed` when every scope shipped cleanly, or `failed` when at
 * least one scope's sink threw (its cursor was held, not advanced; the entries are
 * re-offered next run). `error` holds the first failure's message, if any.
 *
 * @property int $id
 * @property string $status
 * @property int $scopes_scanned
 * @property int $entries_exported
 * @property int $batches
 * @property string|null $sink
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AuditExportRun extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'audit_export_runs';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes_scanned' => 'integer',
            'entries_exported' => 'integer',
            'batches' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
