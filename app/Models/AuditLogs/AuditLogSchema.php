<?php

declare(strict_types=1);

namespace App\Models\AuditLogs;

use App\Platform\AuditLogs\MetadataSchema;
use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The shape one action's events must have, in one environment: which target types it may
 * name, and what its metadata, its actor's and each target's may hold
 * ({@see MetadataSchema}). Every change is a new `version`, and each event records the
 * version it was checked against.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $action
 * @property int $version
 * @property array<string, mixed>|null $actor_metadata
 * @property list<array<string, mixed>>|null $targets
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class AuditLogSchema extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    protected $table = 'app_audit_schemas';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'actor_metadata' => 'array',
            'targets' => 'array',
            'metadata' => 'array',
        ];
    }
}
