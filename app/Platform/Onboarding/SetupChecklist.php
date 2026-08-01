<?php

declare(strict_types=1);

namespace App\Platform\Onboarding;

use App\Models\OnboardingDismissal;
use App\Platform\Entitlements;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;

/**
 * Measures an organization's setup progress against live state.
 *
 * Every step answers a question the data can already answer, so the checklist is
 * never out of date and there is nothing to mark off by hand: invite someone and the
 * invite step ticks, whether you did it from the checklist, the Members page or the
 * API.
 *
 * Steps the organization is not entitled to are dropped rather than shown locked
 * ({@see SetupStepKey::requiresFeature()}) — a list that cannot reach 100% stops
 * being a checklist and becomes an advert.
 */
final readonly class SetupChecklist
{
    public function __construct(
        private Memberships $memberships,
        private Invitations $invitations,
        private Connections $connections,
        private Organizations $organizations,
        private Entitlements $entitlements,
    ) {}

    public function for(string $organizationId): SetupProgress
    {
        $steps = [];

        foreach (SetupStepKey::cases() as $key) {
            $feature = $key->requiresFeature();

            if ($feature !== null && ! $this->entitlements->entitled($organizationId, $feature)) {
                continue;
            }

            $steps[] = new SetupStep($key, $this->isDone($key, $organizationId));
        }

        return new SetupProgress($steps);
    }

    /** Whether this admin has put the checklist away for this organization. */
    public function isDismissed(string $organizationId, string $subjectId): bool
    {
        return OnboardingDismissal::query()
            ->where('organization_id', $organizationId)
            ->where('subject_id', $subjectId)
            ->exists();
    }

    /**
     * Dismissal is per-admin, not per-organization: one administrator deciding they
     * have seen enough should not take the guidance away from a colleague who is
     * still finding their way around.
     */
    public function dismiss(string $organizationId, string $subjectId): void
    {
        OnboardingDismissal::query()->firstOrCreate([
            'organization_id' => $organizationId,
            'subject_id' => $subjectId,
        ]);
    }

    public function restore(string $organizationId, string $subjectId): void
    {
        OnboardingDismissal::query()
            ->where('organization_id', $organizationId)
            ->where('subject_id', $subjectId)
            ->delete();
    }

    private function isDone(SetupStepKey $key, string $organizationId): bool
    {
        return match ($key) {
            // The founder is a member of their own organization, so "someone joined"
            // only counts from the second membership — or from an invitation that has
            // been sent but not yet accepted, which is just as much work done.
            SetupStepKey::InviteTeam => $this->memberships->countForOrganization($organizationId) > 1
                || $this->invitations->pending($organizationId)->isNotEmpty(),

            SetupStepKey::ConnectApp => Client::query()
                ->where('organization_id', $organizationId)
                ->exists(),

            SetupStepKey::DefineRoles => $this->hasRoles($organizationId),

            SetupStepKey::BrandSignIn => $this->hasBranding($organizationId),

            SetupStepKey::SingleSignOn => $this->connections->forOrganization($organizationId) !== null,

            SetupStepKey::SyncUsersIn => Directory::query()
                ->where('organization_id', $organizationId)
                ->exists(),
        };
    }

    /**
     * Either the organization defined its own roles, or one of its apps declared
     * roles it understands — both mean somebody has thought about who can do what,
     * which is what the step is really asking.
     */
    private function hasRoles(string $organizationId): bool
    {
        if (Role::query()->where('organization_id', $organizationId)->whereNull('client_id')->exists()) {
            return true;
        }

        $clientIds = Client::query()
            ->where('organization_id', $organizationId)
            ->pluck('client_id');

        return $clientIds->isNotEmpty()
            && Role::query()
                ->whereIn('client_id', $clientIds)
                ->whereNull('orphaned_at')
                ->exists();
    }

    private function hasBranding(string $organizationId): bool
    {
        $settings = $this->organizations->find($organizationId)?->settings;

        if (! is_array($settings)) {
            return false;
        }

        $appearance = $settings['appearance'] ?? null;
        $logo = $settings['brand_logo_url'] ?? null;

        return (is_array($appearance) && $appearance !== [])
            || (is_string($logo) && $logo !== '');
    }
}
