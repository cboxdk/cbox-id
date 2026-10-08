<?php

declare(strict_types=1);

namespace App\Models\AuditLogs;

use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Model;

/**
 * One target of one {@see AuditLogEvent}, as a row of its own — the index behind "everything
 * that happened to invoice_123". The event keeps its targets too, in order and with their
 * names and metadata; this is only what a filter looks up.
 *
 * @property int $id
 * @property string $event_id
 * @property string $environment_id
 * @property string $organization_id
 * @property string $type
 * @property string $target_id
 */
final class AuditLogEventTarget extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;

    public $timestamps = false;

    protected $table = 'app_audit_event_targets';

    protected $guarded = [];
}
