<?php

declare(strict_types=1);

namespace App\Models;

use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An access role queued for a pending invitation, keyed by (organization, email).
 * Applied to the subject when they accept, then deleted.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $organization_id
 * @property string $email
 * @property string $role_id
 */
final class InvitationRoleGrant extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    protected $table = 'invitation_role_grants';

    protected $guarded = [];
}
