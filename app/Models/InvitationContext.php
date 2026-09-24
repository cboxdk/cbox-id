<?php

declare(strict_types=1);

namespace App\Models;

use App\Platform\Invitations\AppReturnTargets;
use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The app-side half of an organization invitation: which app sent it, where to return the
 * person once they accept, and who sent it. One row per invitation, deleted when the
 * invitation is accepted or withdrawn.
 *
 * `return_to` is stored as it was validated at send time and validated AGAIN at acceptance
 * ({@see AppReturnTargets}): the app's registered redirect URIs
 * can change in the week an invitation is live, and a stored URL is not a standing
 * permission to redirect there.
 *
 * ENVIRONMENT-OWNED like the invitation it annotates, so a context can never be read
 * across the environment boundary the invitation itself cannot cross.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $organization_id
 * @property string $invitation_id
 * @property string|null $client_id
 * @property string|null $return_to
 * @property string|null $invited_by_name
 */
final class InvitationContext extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    protected $table = 'invitation_contexts';

    protected $guarded = [];
}
