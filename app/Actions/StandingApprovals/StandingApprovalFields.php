<?php

declare(strict_types=1);

namespace App\Actions\StandingApprovals;

use App\Actions\Members\TenantRoster;
use App\Http\Resources\Environment\Timestamp;
use App\Models\StandingApproval;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Enterprise\EnterpriseReach;
use App\Platform\StandingApprovals\StandingApprovals;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Standing approvals as the management API shows them — shared by the standing-approval
 * actions, and not one itself.
 */
final class StandingApprovalFields
{
    /**
     * The standing approval $context names, inside the organization it names — or a 404,
     * the same for another organization's id as for a made-up one.
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     */
    public static function find(ActionContext $context): StandingApproval
    {
        $organizationId = EnterpriseReach::requiredOrganization($context, inPath: true);

        return StandingApproval::query()
            ->whereKey($context->string('id'))
            ->where('organization_id', $organizationId)
            ->first() ?? throw ActionRefused::notFound('standing approval');
    }

    /**
     * From inside the organization (its owner's own token or console), only its owner
     * changes a standing approval. The environment's authority keeps its own rules.
     *
     * @throws AuthorizationException
     */
    public static function assertOwnerWhenConfined(ActionContext $context, string $organizationId): ?string
    {
        if (TenantRoster::confined($context) === null) {
            return null;
        }

        $person = TenantRoster::personId($context->principal);

        if (! app(StandingApprovals::class)->isOwner($organizationId, $person)) {
            throw new AuthorizationException('Only the organization\'s owner can change a standing approval.');
        }

        return $person;
    }

    /** @return array<string, mixed> */
    public static function present(StandingApproval $grant): array
    {
        return [
            'id' => $grant->id,
            'organization_id' => $grant->organization_id,
            'client_id' => $grant->client_id,
            'app_client_id' => $grant->app_client_id,
            'environment_ref' => $grant->environment_ref,
            'environment_kind' => $grant->environment_kind,
            'status' => $grant->isActive() ? 'active' : 'revoked',
            'granted_by' => $grant->granted_by,
            'granted_at' => Timestamp::of($grant->granted_at),
            'revoked_at' => Timestamp::of($grant->revoked_at),
            'revoked_by' => $grant->revoked_by,
            'revoke_reason' => $grant->revoke_reason,
            'last_used_at' => Timestamp::of($grant->last_used_at),
            'uses' => $grant->uses,
        ];
    }
}
