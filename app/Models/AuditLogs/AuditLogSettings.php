<?php

declare(strict_types=1);

namespace App\Models\AuditLogs;

use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An environment's audit-log settings, where they are not the deployment's defaults: how long
 * its events are kept, and whether an action with no schema is refused.
 *
 * @property string $id
 * @property string $environment_id
 * @property int $retention_days
 * @property bool $strict_schemas
 */
final class AuditLogSettings extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    protected $table = 'app_audit_settings';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'strict_schemas' => 'boolean',
        ];
    }
}
