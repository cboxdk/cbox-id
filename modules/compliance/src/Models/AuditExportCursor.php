<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The persisted export position for one chain (`scope`). `last_sequence` is the
 * highest audit sequence durably shipped to the sink for this scope; the engine
 * resumes from it and advances it only after a batch exports successfully, so
 * export is idempotent and resumable across runs and process restarts.
 *
 * @property int $id
 * @property string $scope
 * @property string|null $organization_id
 * @property int $last_sequence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AuditExportCursor extends Model
{
    protected $table = 'audit_export_cursors';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_sequence' => 'integer',
        ];
    }
}
