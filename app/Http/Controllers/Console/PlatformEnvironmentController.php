<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Platform\AsOperator;
use App\Actions\Platform\Environments\CreatePlatformEnvironment;
use App\Actions\Platform\Environments\ProvisionEnvironment;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\SimplePaginationProps;
use App\Http\Requests\Console\CreatePlatformEnvironmentRequest;
use App\Http\Requests\Console\ProvisionEnvironmentAdminRequest;
use App\Platform\Console\EnvironmentLineage;
use App\Platform\Console\EnvironmentLineages;
use App\Platform\Console\LikeTerm;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Response;

/**
 * PLATFORM › ENVIRONMENTS — every isolation plane on this install.
 *
 * Operators stand above every environment, so listing and provisioning span every plane
 * (the Environment model is not environment-owned) and pointing the console at one has no
 * identity guard: the route group established the authority already.
 *
 * EVERY ROW CARRIES ITS LINEAGE. This list is flat by nature — it is the whole install at
 * once — and flat it named six planes `production`, `staging`, `acme`, `acme-staging`,
 * `billing-portal` and `demo-co` with nothing saying which customer any of them belonged
 * to. "Production" is a name half the customers on an install will have. Resolved through
 * {@see EnvironmentLineages}, so the owner column costs two queries for the whole table
 * rather than one per row, and so the two environments that legitimately have no account
 * are NAMED rather than rendered as a blank cell.
 *
 * SEARCH AND PAGING. This is the least bounded set in the product, and it had neither
 * while thirteen tenant-facing console pages had both.
 */
final readonly class PlatformEnvironmentController extends ConsoleController
{
    private const PER_PAGE = 25;

    public function index(Request $request, EnvironmentContext $context, EnvironmentLineages $lineages): Response
    {
        $this->assertOperator();

        $term = trim($request->string('q')->toString());
        $activeId = $context->current()?->environmentKey();

        /** @var array{rows: list<array<string, mixed>>, pagination: SimplePaginationProps} $page */
        $page = $context->withoutScope(function () use ($term, $lineages): array {
            /*
             * `id` as a tiebreak: rows created in the same second tie on `created_at`, and
             * an engine may order a tie differently per query — which under paging shows the
             * same environment on two pages, or on neither.
             */
            $query = Environment::query()->orderBy('created_at')->orderBy('id');

            if ($term !== '') {
                // Grouped, so the scope's own predicate cannot be stranded behind the OR if
                // this block ever moves out from under `withoutScope()`. Through LikeTerm,
                // so a literal `_` in a slug or a hostname is a character rather than a
                // wildcard — every one of these three columns routinely contains one.
                $like = LikeTerm::containing($term);

                $query->where(function (Builder $inner) use ($like): void {
                    $inner->whereRaw($like->sqlFor('name'), [$like->pattern])
                        ->orWhereRaw($like->sqlFor('slug'), [$like->pattern])
                        ->orWhereRaw($like->sqlFor('domain'), [$like->pattern]);
                });
            }

            $environments = $query->simplePaginate(self::PER_PAGE)->withQueryString();

            // Batched exactly like the counts below and for the same reason: the lineage of
            // N environments is two queries, not N.
            $lineage = $lineages->for($environments->getCollection());

            // Scoped to the page's ids: these grouped counts used to run across every
            // environment on the install to render twenty-five rows.
            $ids = $environments->getCollection()->map(static fn (Environment $e): string => $e->id)->all();

            /** @var Collection<string, int> $orgCounts */
            $orgCounts = Organization::query()->selectRaw('environment_id, count(*) as c')
                ->whereIn('environment_id', $ids)
                ->groupBy('environment_id')->pluck('c', 'environment_id');

            /** @var Collection<string, int> $userCounts */
            $userCounts = User::query()->selectRaw('environment_id, count(*) as c')
                ->whereIn('environment_id', $ids)
                ->groupBy('environment_id')->pluck('c', 'environment_id');

            $rows = $environments->getCollection()
                ->map(function (Environment $environment) use ($lineage, $orgCounts, $userCounts): array {
                    // Never null: the resolver answers for every environment handed to it.
                    // The fallback is here so the page can render lineage without a null
                    // branch, not because the key can be missing.
                    $owner = $lineage[$environment->id] ?? new EnvironmentLineage(environmentId: $environment->id);

                    return [
                        'id' => $environment->id,
                        'name' => $environment->name,
                        // The OWNER is part of the name here — see the class docblock.
                        'qualifiedName' => $owner->qualify($environment->name),
                        'slug' => $environment->slug,
                        'domain' => $environment->domain,
                        'orgs' => (int) ($orgCounts[$environment->id] ?? 0),
                        'users' => (int) ($userCounts[$environment->id] ?? 0),
                        'lineage' => $this->lineage($owner),
                        'provisionHref' => route('platform.environments.provision', $environment->id),
                    ];
                })->all();

            return ['rows' => array_values($rows), 'pagination' => SimplePaginationProps::from($environments)];
        });

        return $this->page('console/platform/environments', 'Environments', [
            'help' => HelpProps::for(HelpTopic::Environments),
            'environments' => $page['rows'],
            'pagination' => $page['pagination'],
            'search' => $term,
            'activeId' => $activeId,
            'storeHref' => route('platform.environments.store'),
            'targetHref' => route('platform.environment.switch'),
            'customersHref' => route('platform.workspaces'),
        ]);
    }

    /**
     * Create an environment through {@see CreatePlatformEnvironment}, the action the
     * operator API runs too: created WITHOUT the domain — an unverified domain written here
     * makes the plane unusable rather than reachable — with its signing key warmed. The
     * operator verifies the domain by DNS, through the same door every other writer uses.
     */
    public function store(CreatePlatformEnvironmentRequest $request): RedirectResponse
    {
        $this->assertOperator();

        $domain = $request->domain();

        $result = $this->act(CreatePlatformEnvironment::class, [
            'name' => $request->name(),
            'domain' => $domain,
        ], ['name' => 'name', 'domain' => 'domain'], 'name');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var array{name: string} $environment */
        $environment = $result->payload;

        return back()->with('status', $domain === null
            ? 'Environment "'.$environment['name'].'" created.'
            : 'Environment "'.$environment['name'].'" created. Add '.$domain.' from the environment\'s domain settings to verify it by DNS — an unverified domain cannot be its issuer.');
    }

    /**
     * Bootstrap a plane — its first organization and an owner admin — through
     * {@see ProvisionEnvironment}, which asks every question inside the target environment:
     * an email is unique per plane, and the password policy is the tenant's own.
     */
    public function provision(ProvisionEnvironmentAdminRequest $request, string $environment): RedirectResponse
    {
        $this->assertOperator();

        $result = $this->act(ProvisionEnvironment::class, [
            'environment_id' => $environment,
            'organization_name' => $request->orgName(),
            'admin_name' => $request->adminName(),
            'admin_email' => $request->adminEmail(),
            'admin_password' => $request->adminPassword(),
        ], [
            'organization_name' => 'orgName',
            'admin_name' => 'adminName',
            'admin_email' => 'adminEmail',
            'admin_password' => 'adminPassword',
        ], 'orgName');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return back()->with('status', 'Provisioned an organization and admin in "'.AsOperator::environment($environment)->name.'".');
    }

    /**
     * The lineage as the page reads it: who owns the plane, or what it is instead of a
     * customer. Both no-account cases are said out loud rather than rendered as a blank
     * cell that looks like a broken join.
     *
     * @return array<string, mixed>
     */
    private function lineage(EnvironmentLineage $lineage): array
    {
        return [
            'owner' => $lineage->owner(),
            'organizationId' => $lineage->organizationId,
            'organizationName' => $lineage->organizationName,
            'organizationHref' => $lineage->belongsToOrganization() && $lineage->organizationId !== null
                ? route('platform.workspaces.show', $lineage->organizationId)
                : null,
            'projectName' => $lineage->projectName,
            'isPlatformRoot' => $lineage->isPlatformRoot,
            'isUnattached' => $lineage->isUnattached(),
            'note' => $lineage->note(),
        ];
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
