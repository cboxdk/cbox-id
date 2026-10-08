<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Principal\Principal;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The organization an action acts on, when the caller names one — checked the same way
 * for every door.
 *
 * Many settings exist at two altitudes: the environment's own (null) and one
 * organization's override. A management key acts with the environment's authority, so it
 * may name either, as long as the organization is in THIS environment (the model is
 * environment-scoped, so an id from anywhere else resolves to nothing). A person on the
 * ORGANIZATION console — or acting through a token they signed in for, which carries the
 * same confinement ({@see Principal::confinedToOrganization()})
 * — may only ever reach their own organization: the console page passes
 * the scope's organization, and an action reached with any other — or with none, meaning
 * the environment's default every tenant inherits — is a forged request, refused here
 * rather than trusted to the page that built it.
 *
 * HOW it is refused matters as much as that it is. Another organization's id is answered
 * exactly as an id that never existed — a 404 when the URL names it, the same field error
 * as an unknown organization when the body does — never a 403, which would confirm the
 * organization is there and is somebody else's. Only the missing organization ("the
 * environment's default") stays a 403: there is no id in that request to keep secret.
 * The cross-tenant sweep (tests/Feature/Actions/CrossTenantIdSweepTest.php) holds every
 * action to this.
 */
final class OrganizationTarget
{
    /**
     * @param  bool  $inPath  Named in the URL: an unknown one is a 404, not a field error.
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     */
    public static function check(ActionContext $context, ?string $organizationId, bool $inPath = false): ?string
    {
        $confinedTo = $context->principal->confinedToOrganization();

        if ($confinedTo !== null && $organizationId === null) {
            throw new AuthorizationException('An organization administrator may only change their own organization.');
        }

        $foreign = $confinedTo !== null && $organizationId !== $confinedTo;

        if ($organizationId !== null && ($foreign || Organization::query()->whereKey($organizationId)->doesntExist())) {
            throw $inPath
                ? ActionRefused::notFound('organization')
                : ActionRefused::because('organization_not_found', 'No organization with that organization_id exists in this environment.', 'organization_id');
        }

        return $organizationId;
    }
}
