<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use App\Platform\OAuth\Contracts\AuthorizationOrganizations;
use App\Platform\OAuth\Exceptions\OrganizationCreationRefused;
use App\Platform\OAuth\ValueObjects\OrganizationChoice;
use App\Platform\SignupPolicy;
use App\Platform\ThrottleScope;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Exceptions\SlugAlreadyTaken;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * {@see AuthorizationOrganizations}, over the framework's organization tables.
 *
 * EVERY CONDITION IS IN THE WHERE CLAUSE — environment, organization status, membership
 * status, the person — and the reads run with the tenant scope lifted. That is deliberate:
 * a check that only holds because an ambient scope happened to filter the rows first can
 * never fail in a test and can fail in production the day the scope is suspended around
 * it. Binding every condition here means the answer is the same either way.
 */
final readonly class AuthorizationOrganizationService implements AuthorizationOrganizations
{
    /** Organizations one person may found from apps, per hour, per environment. */
    private const CREATE_LIMIT = 5;

    private const CREATE_WINDOW_SECONDS = 3600;

    public function __construct(
        private EnvironmentContext $environments,
        private TenantContext $tenants,
        private Organizations $organizations,
        private Memberships $memberships,
        private SignupPolicy $signup,
    ) {}

    public function choicesFor(string $userId): array
    {
        return $this->query($userId, null);
    }

    public function usableBy(string $userId, string $organizationId): ?OrganizationChoice
    {
        return $this->query($userId, $organizationId)[0] ?? null;
    }

    public function creationOffered(): bool
    {
        return $this->signup->allowsCreatingOrganizations();
    }

    public function create(string $userId, string $name): OrganizationChoice
    {
        if (! $this->creationOffered()) {
            throw OrganizationCreationRefused::notOffered();
        }

        $key = 'oauth-create-organization|'.ThrottleScope::key().'|'.$userId;

        if (RateLimiter::tooManyAttempts($key, self::CREATE_LIMIT)) {
            throw OrganizationCreationRefused::tooMany(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::CREATE_WINDOW_SECONDS);

        /*
         * ONE TRANSACTION, so there is never an organization with no owner: the framework's
         * create and add each open their own, and nested they commit together. An
         * organization nobody owns is one nobody can invite into, hand over or close.
         *
         * `add(Owner)` rather than a transfer: `transferOwnership()` is how an EXISTING
         * organization changes hands, and founding one is the one moment an owner is made
         * from nothing — the same call the signup form and the console make.
         */
        $organization = DB::transaction(function () use ($userId, $name): Organization {
            $organization = $this->createWithFreeSlug($name);
            $this->memberships->add($organization->id, $userId, MembershipRole::Owner);

            return $organization;
        });

        return new OrganizationChoice($organization->id, $organization->name, MembershipRole::Owner);
    }

    /**
     * @return list<OrganizationChoice>
     */
    private function query(string $userId, ?string $organizationId): array
    {
        $environment = $this->environments->current()?->environmentKey();

        if ($environment === null) {
            return [];
        }

        /*
         * Both scopes lifted — the environment's and the organization's (a membership is
         * tenant-owned, and "every organization this person is in" is cross-tenant by
         * nature) — because every one of them is restated in the WHERE clauses below.
         */
        /** @var list<OrganizationChoice> $choices */
        $choices = $this->environments->withoutScope(fn (): array => $this->tenants->withoutScope(function () use ($environment, $userId, $organizationId): array {
            $memberships = Membership::query()
                ->where('environment_id', $environment)
                ->where('user_id', $userId)
                ->where('status', MembershipStatus::Active->value)
                ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
                ->get(['organization_id', 'role'])
                ->keyBy('organization_id');

            if ($memberships->isEmpty()) {
                return [];
            }

            $organizations = Organization::query()
                ->where('environment_id', $environment)
                ->where('status', OrganizationStatus::Active->value)
                ->whereIn('id', $memberships->keys()->all())
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name']);

            $choices = [];

            foreach ($organizations as $organization) {
                $membership = $memberships->get($organization->id);

                if ($membership instanceof Membership) {
                    $choices[] = new OrganizationChoice($organization->id, $organization->name, $membership->role);
                }
            }

            return $choices;
        }));

        return $choices;
    }

    /**
     * A slug nobody in this environment holds. Checked first, and retried once with a
     * random tail when two founders race for the same name between the check and the
     * insert — the loser gets a slightly longer slug, never an error.
     */
    private function createWithFreeSlug(string $name): Organization
    {
        $base = Str::slug($name) ?: 'organization';
        $base = mb_substr($base, 0, 60);
        $slug = $base;
        $n = 1;

        while ($this->organizations->bySlug($slug) !== null && $n < 50) {
            $slug = $base.'-'.(++$n);
        }

        try {
            return $this->organizations->create(new NewOrganization($name, $slug));
        } catch (SlugAlreadyTaken) {
            return $this->organizations->create(new NewOrganization($name, $base.'-'.Str::lower(Str::random(6))));
        }
    }
}
