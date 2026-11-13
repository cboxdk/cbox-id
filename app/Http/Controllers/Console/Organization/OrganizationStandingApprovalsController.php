<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console\Organization;

use App\Actions\StandingApprovals\ListStandingApprovals;
use App\Actions\StandingApprovals\RevokeStandingApproval;
use App\Models\StandingApproval;
use Cbox\Id\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * AN ORGANIZATION › STANDING APPROVALS — the machine clients whose deployments to one
 * environment of an app run without a person's click (Cbox Infra's CI deploys).
 *
 * Seen and turned off here (`organizations.standing_approvals.revoke`); never turned on.
 * Only the organization's OWNER turns one on, from the app or with the API naming them
 * (`organizations.standing_approvals.grant`): this console's people administer the
 * environment, they are not that owner, and a standing "yes" to a credential is theirs alone
 * to give. Taking one back is never gated on being allowed to give it.
 */
final readonly class OrganizationStandingApprovalsController extends OrganizationTabController
{
    public function index(): Response
    {
        $organization = $this->organization();

        $grants = StandingApproval::query()
            ->where('organization_id', $organization->id)
            ->where(static fn ($query) => $query->whereNull('revoked_at')->orWhere('revoked_at', '>=', now()->subDays(ListStandingApprovals::DAYS)))
            ->orderByRaw('revoked_at is not null')
            ->orderByDesc('granted_at')
            ->get();

        $people = User::query()->whereIn('id', $grants->pluck('granted_by')->unique()->all())->get()->keyBy('id');

        return $this->page('environment/organizations/tabs/standing-approvals', __('console.organization_standing_approvals_title', ['name' => $organization->name]), [
            'approvals' => $grants->map(static function (StandingApproval $grant) use ($people, $organization): array {
                $owner = $people->get($grant->granted_by);

                return [
                    'id' => $grant->id,
                    'clientId' => $grant->client_id,
                    'appClientId' => $grant->app_client_id,
                    'environmentRef' => $grant->environment_ref,
                    'environmentKind' => $grant->environment_kind,
                    'grantedBy' => $owner instanceof User ? ($owner->name ?? $owner->email ?? $grant->granted_by) : $grant->granted_by,
                    'grantedAt' => $grant->granted_at->toIso8601String(),
                    'lastUsedAt' => $grant->last_used_at?->toIso8601String(),
                    'uses' => $grant->uses,
                    'active' => $grant->isActive(),
                    'revokeHref' => $grant->isActive()
                        ? route('environment.organizations.standing-approvals.revoke', ['organization' => $organization->id, 'grant' => $grant->id])
                        : null,
                ];
            })->values()->all(),
        ]);
    }

    /** "Turn off", through the action a management key turns one off with. */
    public function destroy(string $grant): RedirectResponse
    {
        $organization = $this->organization();

        $result = $this->act(RevokeStandingApproval::class, [
            'organization_id' => $organization->id,
            'id' => $grant,
        ]);

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', __('console.standing_approval_turned_off_0f858a98'));
    }
}
