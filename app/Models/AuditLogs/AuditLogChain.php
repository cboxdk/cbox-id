<?php

declare(strict_types=1);

namespace App\Models\AuditLogs;

use App\Platform\AuditLogs\AuditLogChains;
use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The head of one organization's audit-event chain — the row an append locks
 * ({@see AuditLogChains}), and where the prune last cut the chain.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $organization_id
 * @property int $head_sequence
 * @property string $head_hash
 * @property int $pruned_through_sequence
 * @property string|null $pruned_through_hash
 */
final class AuditLogChain extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    protected $table = 'app_audit_chains';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'head_sequence' => 'integer',
            'pruned_through_sequence' => 'integer',
        ];
    }
}
