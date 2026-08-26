<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Requests\Console\CreateTenantOrganizationRequest;
use App\Platform\Console\LikeTerm;
use Carbon\CarbonInterface;
use Cbox\Id\AuditQuery\Contracts\AuditReader;
use Cbox\Id\AuditQuery\ValueObjects\AuditQueryFilter;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\MfaFactor;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementReader;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Models\ServiceAccount;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\OrganizationHierarchy;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Enums\OrganizationType;
use Cbox\Id\Organization\Exceptions\CannotReparent;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Response;

/**
 * PLATFORM › ORGANIZATIONS — every tenant in the TARGETED environment, as the management
 * tree (reseller → customer → sub-unit, arbitrary depth).
 *
 * Organizations are environment-owned, so every query here is naturally scoped to the
 * plane the console is pointed at: this is the whole plane and never another's, and an id
 * from outside it resolves to null → 404. That is the correct deny-by-default — an
 * operator who wants another plane targets it first, from a page that says so.
 *
 * The one mutation on both pages, suspend/reactivate, routes through the
 * {@see Organizations} contract exactly like every other caller, so it is attributed to
 * the acting operator and recorded on the tenant's own audit trail; a direct `update()`
 * would bypass both.
 */
final readonly class PlatformOrganizationController extends ConsoleController
{
    /** Members rendered on the detail page. The total is shown beside it. */
    private const MEMBER_CAP = 50;

    /** How far back the sign-in tile looks. */
    private const SIGN_IN_DAYS = 30;

    public function index(Request $request): Response
    {
        $this->assertOperator();

        $term = trim($request->string('q')->toString());

        /*
         * SEARCH, AND DELIBERATELY NO PAGING.
         *
         * This list is a management TREE, flattened depth-first so the table reads as the
         * hierarchy. Paging the flattened rows would cut the tree at an arbitrary depth — a
         * child on page two with its parent on page one reads as a root, which is worse
         * than a long page. If the estate ever outgrows one page, the answer is paging the
         * ROOTS and rendering each subtree whole, not paging these rows.
         */
        $organizations = Organization::query()->orderBy('name')
            ->when($term !== '', function (Builder $query) use ($term): void {
                // Grouped, so the environment scope's own predicate is not stranded behind
                // the OR — and through LikeTerm, so a literal `_` in a slug is a character.
                $like = LikeTerm::containing($term);

                $query->where(function (Builder $inner) use ($like): void {
                    $inner->whereRaw($like->sqlFor('name'), [$like->pattern])
                        ->orWhereRaw($like->sqlFor('slug'), [$like->pattern]);
                });
            })
            ->get(['id', 'name', 'slug', 'type', 'status', 'parent_id']);

        /*
         * COUNTED FOR THE ROWS ON SCREEN, not for the environment. Ungated, this is a full
         * group-by over every membership row in the plane — one row per person per
         * organization — to decorate a list already filtered to a handful.
         */
        /** @var list<string> $listedIds */
        $listedIds = $organizations->map(static fn (Organization $o): string => $o->id)->values()->all();

        /** @var Collection<string, int> $memberCounts */
        $memberCounts = $listedIds === []
            ? collect()
            : Membership::query()->selectRaw('organization_id, count(*) as c')
                ->whereIn('organization_id', $listedIds)
                ->groupBy('organization_id')->pluck('c', 'organization_id');

        return $this->page('console/platform/organizations', 'Organizations', [
            'organizations' => $this->tree($organizations, $memberCounts),
            // The flat list the two parent selectors are built from.
            'all' => $organizations->map(static fn (Organization $o): array => [
                'id' => $o->id,
                'name' => $o->name,
            ])->values()->all(),
            'search' => $term,
            'types' => array_map(static fn (OrganizationType $type): array => [
                'value' => $type->value,
                'label' => Str::headline($type->value),
            ], OrganizationType::cases()),
            'storeHref' => route('platform.organizations.store'),
        ]);
    }

    public function store(CreateTenantOrganizationRequest $request, Organizations $organizations): RedirectResponse
    {
        $this->assertOperator();

        $organizations->create(new NewOrganization(
            name: $request->name(),
            slug: $this->uniqueSlug($request->name()),
            type: $request->type(),
            parentId: $request->parentId(),
        ));

        return back()->with('status', 'Organization created.');
    }

    /** A read-only drill-down into one tenant, WITHOUT switching the console. */
    public function show(
        string $organization,
        Organizations $organizations,
        Memberships $memberships,
        Subjects $subjects,
        OrganizationHierarchy $hierarchy,
        Connections $connections,
        DomainVerification $domains,
        EntitlementReader $entitlements,
        AuditReader $audit,
    ): Response {
        $this->assertOperator();

        // Scoped lookup: an organization outside the current plane returns null → 404, so
        // nothing from another environment is rendered or leaked.
        $tenant = $organizations->find($organization);

        abort_if($tenant === null, 404);

        $allMemberships = $memberships->forOrganization($tenant->id);

        // Capped so a huge tenant cannot blow up the page; the total is shown beside it.
        $members = $allMemberships->take(self::MEMBER_CAP)
            ->map(function (Membership $membership) use ($subjects, $tenant): array {
                $subject = $subjects->find($membership->user_id);
                $role = $membership->role->value;

                return [
                    'userId' => $membership->user_id,
                    'email' => $subject?->email,
                    'name' => $subject?->name,
                    'role' => $role,
                    'status' => $membership->status->value,
                    /*
                     * Owners and admins are never impersonable — their elevated surface is
                     * off-limits — and the row says so rather than offering a form that
                     * would be refused. The refusal itself lives in the impersonation
                     * controller; this is the half a person can see.
                     */
                    'impersonateHref' => in_array($role, ['owner', 'admin'], true)
                        ? null
                        : route('platform.impersonate', $membership->user_id),
                    'organizationId' => $tenant->id,
                ];
            })->values()->all();

        $memberUserIds = $allMemberships->pluck('user_id')->unique()->values()->all();
        $memberUserCount = count($memberUserIds);

        // Users with at least one CONFIRMED MFA factor (COUNT DISTINCT user_id).
        $mfaUsers = $memberUserCount === 0 ? 0 : MfaFactor::query()
            ->whereIn('user_id', $memberUserIds)
            ->whereNotNull('confirmed_at')
            ->distinct()
            ->count('user_id');

        // Active (non-revoked, non-expired) sessions belonging to the tenant's users.
        $activeSessions = $memberUserCount === 0 ? 0 : Session::query()
            ->whereIn('user_id', $memberUserIds)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->count();

        $connection = $connections->forOrganization($tenant->id);

        $domainList = array_map(static fn (VerifiedDomain $domain): array => [
            'domain' => $domain->domain,
            'verifiedAt' => $domain->verified_at?->toDayDateTimeString(),
            'capture' => $domain->capture,
        ], $domains->forOrganization($tenant->id));

        $entitlementList = [];

        foreach ($entitlements->all($tenant->id) as $key => $value) {
            $entitlementList[] = [
                'key' => $key,
                'value' => json_encode($value->value),
                'mode' => $value->mode->value,
                'source' => $value->source->value,
            ];
        }

        $ancestors = [];

        foreach ($hierarchy->ancestors($tenant->id) as $ancestorId) {
            $ancestor = $organizations->find($ancestorId);

            if ($ancestor !== null) {
                $ancestors[] = [
                    'id' => $ancestor->id,
                    'name' => $ancestor->name,
                    'href' => route('platform.organization', $ancestor->id),
                ];
            }
        }

        $createdAt = $tenant->getAttribute('created_at');

        return $this->page('console/platform/organizations/show', $tenant->name, [
            'organization' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status->value,
                'active' => $tenant->status === OrganizationStatus::Active,
                'type' => $tenant->type->value,
                'createdAt' => $createdAt instanceof CarbonInterface ? $createdAt->toDayDateTimeString() : null,
            ],
            'members' => $members,
            'memberTotal' => $allMemberships->count(),
            'usage' => [
                'members' => $memberUserCount,
                'mfaUsers' => $mfaUsers,
                'mfaAdoption' => $memberUserCount === 0 ? 0 : (int) round($mfaUsers / $memberUserCount * 100),
                'sessions' => $activeSessions,
                'connections' => $connection === null ? 0 : 1,
                'domains' => count($domainList),
                'clients' => Client::query()->where('organization_id', $tenant->id)->count(),
                'serviceAccounts' => ServiceAccount::query()->where('organization_id', $tenant->id)->count(),
                'signIns' => $this->recentSignIns($tenant->id, $audit),
            ],
            'childCount' => count($hierarchy->descendants($tenant->id)),
            'sso' => $connection === null ? null : [
                'type' => $connection->type->value,
                'name' => $connection->name,
                'status' => $connection->status->value,
            ],
            'domains' => $domainList,
            'entitlements' => $entitlementList,
            'recent' => $this->recentActivity($tenant->id, $audit),
            'ancestors' => $ancestors,
            'indexHref' => route('platform.organizations'),
            'toggleHref' => route('platform.organizations.toggle', $tenant->id),
        ]);
    }

    public function toggle(string $organization, Organizations $organizations): RedirectResponse
    {
        $this->assertOperator();

        $actorId = $this->scope->operator()?->id;

        abort_if($actorId === null, 403);

        // Resolved through the SCOPED reader, exactly like the read — so an id from
        // another plane 404s here as it does there. Returning silently instead would leave
        // an operator pressing a control that reports success and changes nothing.
        $tenant = $organizations->find($organization);

        abort_if($tenant === null, 404);

        if ($tenant->status === OrganizationStatus::Active) {
            $organizations->suspend($tenant->id, $actorId);

            return back()->with('status', 'Organization suspended.');
        }

        $organizations->reactivate($tenant->id, $actorId);

        return back()->with('status', 'Organization reactivated.');
    }

    public function reparent(Request $request, string $organization, OrganizationHierarchy $hierarchy, Organizations $organizations): RedirectResponse
    {
        $this->assertOperator();

        /*
         * BOTH IDS ARE RESOLVED THROUGH THE SCOPED READER before anything moves. The
         * hierarchy contract takes ids and does not itself ask which plane they belong to,
         * so an id from another environment reaching `move()` would splice one plane's
         * tenant into another's tree. Organizations are environment-owned, so `find()` is
         * the predicate — and a stranger 404s rather than being reported as a cycle.
         */
        abort_if($organizations->find($organization) === null, 404);

        $parentId = trim((string) $request->string('parentId'));
        $parentId = $parentId === '' ? null : $parentId;

        if ($parentId !== null) {
            abort_if($organizations->find($parentId) === null, 404);
        }

        try {
            // move() rewrites the closure subtree and guards against cycles.
            $hierarchy->move($organization, $parentId);
        } catch (CannotReparent) {
            return back()->withErrors([
                'parentId' => 'That would create a cycle in the hierarchy — nothing was moved.',
            ]);
        }

        return back()->with('status', 'Hierarchy updated.');
    }

    /**
     * The tenant tree, flattened depth-first so the table reads as the hierarchy.
     *
     * A FILTERED SET HAS ORPHANS, and they must not vanish. The walk starts at the roots,
     * so an organization whose parent did not match the search would be grouped under a
     * parent key nothing ever visits, and a match would silently not be listed — a search
     * that hides matches is worse than no search. So a row whose parent is absent from the
     * result set is treated as a root for display: it renders at depth 0 rather than under
     * a parent that is not on screen, which is the honest thing to show for a filtered view.
     *
     * @param  Collection<int, Organization>  $organizations
     * @param  Collection<string, int>  $memberCounts
     * @return list<array<string, mixed>>
     */
    private function tree(Collection $organizations, Collection $memberCounts): array
    {
        $present = $organizations->keyBy(static fn (Organization $o): string => $o->id);

        /** @var Collection<string, Collection<int, Organization>> $byParent */
        $byParent = $organizations->groupBy(static function (Organization $o) use ($present): string {
            $parent = $o->parent_id;

            return $parent !== null && $present->has($parent) ? $parent : '';
        });

        /** @var list<array<string, mixed>> $rows */
        $rows = [];

        $walk = function (string $parentKey, int $depth) use (&$walk, $byParent, $memberCounts, &$rows): void {
            /** @var Collection<int, Organization> $children */
            $children = $byParent->get($parentKey, new Collection);

            foreach ($children as $child) {
                $rows[] = [
                    'id' => $child->id,
                    'name' => $child->name,
                    'slug' => $child->slug,
                    'type' => $child->type->value,
                    'status' => $child->status->value,
                    'active' => $child->status === OrganizationStatus::Active,
                    'parentId' => $child->parent_id,
                    'depth' => $depth,
                    'members' => (int) ($memberCounts[$child->id] ?? 0),
                    'href' => route('platform.organization', $child->id),
                    'toggleHref' => route('platform.organizations.toggle', $child->id),
                    'reparentHref' => route('platform.organizations.reparent', $child->id),
                ];

                $walk($child->id, $depth + 1);
            }
        };

        $walk('', 0);

        return $rows;
    }

    /**
     * Sign-ins on the tenant's trail in the last 30 days.
     *
     * The reader paginates oldest-first with a 500-row cap and no time predicate, so this
     * reads the login-filtered window and counts what falls inside the boundary. A
     * per-tenant drill-down never approaches the cap; the tile is a recent-activity signal,
     * not a billing figure.
     */
    private function recentSignIns(string $organizationId, AuditReader $audit): int
    {
        $since = now()->subDays(self::SIGN_IN_DAYS);

        $page = $audit->query(new AuditQueryFilter(
            organizationId: $organizationId,
            action: 'user.login',
            limit: 500,
        ));

        $count = 0;

        foreach ($page->items as $entry) {
            if ($entry->recorded_at !== null && $entry->recorded_at->greaterThanOrEqualTo($since)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The newest twenty entries on the tenant's trail.
     *
     * The reader paginates oldest-first with no descending primitive, so this reads a
     * capped window and takes its newest tail — comfortably covering a per-tenant
     * drill-down's recent activity.
     *
     * @return list<array<string, mixed>>
     */
    private function recentActivity(string $organizationId, AuditReader $audit): array
    {
        $page = $audit->query(new AuditQueryFilter(organizationId: $organizationId, limit: 200));

        return array_map(static fn (AuditEntry $entry): array => [
            'action' => $entry->action,
            'actorType' => $entry->actor_type->value,
            'actorId' => $entry->actor_id,
            'recordedAt' => $entry->recorded_at?->toDayDateTimeString(),
        ], array_slice(array_reverse($page->items), 0, 20));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $n = 2;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /**
     * 404, not 403: the platform console does not confirm to a stranger that this
     * deployment has a staff console at that address.
     */
    private function assertOperator(): void
    {
        abort_unless($this->scope->isPlatformOperator(), 404);
    }
}
