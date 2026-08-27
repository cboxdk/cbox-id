<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Platform\Console\LikeTerm;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * PLATFORM › SEARCH — find an organization or a user across EVERY environment, above the
 * plane the console is currently pinned to.
 *
 * Organizations and users are environment-owned, so an ordinary query only ever sees the
 * current plane. Operators legitimately span planes, so the search runs inside
 * {@see EnvironmentContext::withoutScope()} — the provisioning-only escape that suspends the
 * hard environment scope — letting one query reach every plane. Each row carries its own
 * environment id, resolved to a human plane label.
 *
 * THE SCREEN NEVER MUTATES AND NEVER SWITCHES THE CONSOLE. A result's "View" hands off to a
 * small controller jump that re-points the console at the result's OWN plane first, so the
 * plane-scoped detail page then resolves.
 */
final readonly class PlatformSearchController extends ConsoleController
{
    /** Below this length the page shows a hint instead of running a query. */
    private const MIN_TERM = 2;

    /** Per-kind result cap — a broad term cannot blow up the page or the query. */
    private const RESULT_CAP = 25;

    public function __invoke(Request $request, EnvironmentContext $environments, TenantContext $tenants): Response
    {
        // 404, not 403: the platform console does not confirm to a stranger that this
        // deployment has a staff console at that address.
        abort_unless($this->scope->isPlatformOperator(), 404);

        $term = trim($request->string('term')->toString());

        if (mb_strlen($term) < self::MIN_TERM) {
            return $this->page('console/platform/search', 'Search', [
                'term' => $term,
                'ready' => false,
                'organizations' => [],
                'users' => [],
            ]);
        }

        $like = LikeTerm::containing($term);

        $results = $environments->withoutScope(function () use ($like, $tenants): array {
            // One [id => Environment] map for every plane label, resolved once.
            $planes = Environment::query()->get(['id', 'name', 'slug'])->keyBy('id');

            $organizations = Organization::query()
                ->whereRaw($like->sqlFor('name'), [$like->pattern])
                ->orWhereRaw($like->sqlFor('slug'), [$like->pattern])
                ->orderBy('name')
                ->limit(self::RESULT_CAP)
                ->get(['id', 'name', 'slug', 'status', 'environment_id']);

            $users = User::query()
                ->whereRaw($like->sqlFor('email'), [$like->pattern])
                ->orWhereRaw($like->sqlFor('name'), [$like->pattern])
                ->orderBy('email')
                ->limit(self::RESULT_CAP)
                ->get(['id', 'name', 'email', 'environment_id']);

            /*
             * `Membership` is ALSO tenant-owned, so its tenant scope is deny-by-default when
             * the console pins no tenant. That scope is suspended too (the environment scope
             * is already suspended by the enclosing call) to read each user's membership for
             * context — best-effort, purely to label the result.
             */
            $membershipsByUser = $tenants->withoutScope(
                static fn () => Membership::query()
                    ->whereIn('user_id', $users->pluck('id')->all())
                    ->get(['user_id', 'organization_id'])
            )->groupBy('user_id');

            $membershipOrganizations = Organization::query()
                ->whereIn('id', $membershipsByUser->flatten(1)->pluck('organization_id')->unique()->values()->all())
                ->get(['id', 'name'])
                ->keyBy('id');

            return [
                'organizations' => $organizations->map(function (Organization $organization) use ($planes): array {
                    $environmentId = $organization->getAttribute('environment_id');
                    $plane = is_string($environmentId) ? $planes->get($environmentId) : null;

                    return [
                        'id' => $organization->id,
                        'name' => $organization->name,
                        'slug' => $organization->slug,
                        'suspended' => $organization->status->value === 'suspended',
                        // Named rather than blank: a row whose plane cannot be resolved is a
                        // thing an operator should see, not a gap.
                        'plane' => $plane->name ?? 'Unknown plane',
                        'href' => route('platform.search.jump', $organization->id),
                    ];
                })->values()->all(),
                'users' => $users->map(function (User $user) use ($planes, $membershipsByUser, $membershipOrganizations): array {
                    $plane = $planes->get($user->environment_id);

                    $organizations = [];

                    foreach ($membershipsByUser->get($user->id) ?? [] as $membership) {
                        $organization = $membershipOrganizations->get($membership->organization_id);

                        if ($organization !== null) {
                            $organizations[] = ['id' => $organization->id, 'name' => $organization->name];
                        }
                    }

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'plane' => $plane->name ?? 'Unknown plane',
                        'organizations' => $organizations,
                        /*
                         * A user is not a page: the console has no cross-plane person view, so
                         * the row opens the FIRST organization they belong to. Somebody in no
                         * organization has nowhere to be sent, and the row says so rather than
                         * offering a control that goes nowhere.
                         */
                        'href' => $organizations === []
                            ? null
                            : route('platform.search.jump', $organizations[0]['id']),
                    ];
                })->values()->all(),
            ];
        });

        return $this->page('console/platform/search', 'Search', [
            'term' => $term,
            'ready' => true,
            'organizations' => $results['organizations'],
            'users' => $results['users'],
        ]);
    }
}
