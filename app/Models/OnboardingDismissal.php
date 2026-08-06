<?php

declare(strict_types=1);

namespace App\Models;

use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One administrator putting the setup checklist away for one organization.
 *
 * Kept as a row rather than a flag on the organization because the decision is
 * personal: an owner who has seen the checklist a hundred times should be able to
 * hide it without taking the guidance away from the colleague who joined yesterday.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $organization_id
 * @property string $subject_id
 */
final class OnboardingDismissal extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    protected $table = 'onboarding_dismissals';

    protected $guarded = [];
}
