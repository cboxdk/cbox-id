<?php

declare(strict_types=1);

namespace Cbox\Id\Billing\Http\Controllers;

use App\Http\Controllers\Console\ConsoleController;
use Cbox\Id\Kernel\Usage\Enums\UsageMetric;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Inertia\Response;

/**
 * IDENTITY PLATFORM › BILLING — the organization's usage rollup and its per-project plans.
 *
 * THE BILLING ANCHOR IS THE PROJECT, not the organization: one customer can own several
 * independently-billed IdP products. So the page lists each project's plan and environment
 * allowance, then rolls usage up across every environment those projects own.
 *
 * Every figure is queried live from the organization's own environments — nothing here is
 * fabricated or cached.
 */
final readonly class BillingController extends ConsoleController
{
    public function __invoke(OrganizationProjects $projects): Response|RedirectResponse
    {
        /*
         * VISIBLE TO ROLES THAT CAN READ IT — owner, admin, billing, and the read-only
         * viewer — but not to a technical Developer role.
         *
         * A REDIRECT rather than a 403, and that is the console's own answer for somebody
         * arriving where they may not go: it sends them somewhere they can be. Under Volt
         * this had to be said twice — once in `mount()` for the first request and once in
         * `boot()` for every action, because a page already open re-hydrated from its
         * snapshot and a downgraded person kept a working tab. There is one door now.
         */
        if ($this->scope->capabilities()?->canReadBilling() !== true) {
            return redirect()->route('projects');
        }

        $organizationId = $this->scope->organizationId();

        /** @var Collection<int, Project> $organizationProjects */
        $organizationProjects = $organizationId === null
            ? new Collection
            : $projects->forOrganization($organizationId);

        /*
         * ONE GROUPED READ, not a count inside the map. An organization with thirty
         * projects paid thirty round trips to render a page whose whole content is "how
         * much of your allowance is used" — and the answer for every project at once is a
         * single GROUP BY.
         */
        /** @var SupportCollection<string, int> $environmentCounts */
        $environmentCounts = $organizationProjects->isEmpty()
            ? new SupportCollection
            : Environment::query()
                ->whereIn('project_id', $organizationProjects->pluck('id'))
                ->groupBy('project_id')
                ->selectRaw('project_id, count(*) as aggregate')
                ->pluck('aggregate', 'project_id');

        $projectRows = $organizationProjects->map(fn (Project $project): array => [
            'id' => $project->id,
            'name' => $project->name,
            'used' => (int) ($environmentCounts[$project->id] ?? 0),
            'limit' => $project->environment_limit,
            'href' => route('projects.show', $project->id),
        ])->values()->all();

        /*
         * THROUGH THE PROJECTS, because `environments.account_id` is gone: it was a
         * denormalised copy of ownership that made this a single read, and a copy of
         * ownership is a second place for ownership to be wrong.
         */
        $environmentIds = Environment::query()
            ->whereIn('project_id', array_column($projectRows, 'id'))
            ->pluck('id');

        $monthStart = now()->startOfMonth()->format('Y-m-d');

        return $this->page('billing::billing', 'Billing', [
            'projects' => $projectRows,
            'usage' => [
                'organizations' => $environmentIds->isEmpty() ? 0 : DB::table('organizations')
                    ->whereIn('environment_id', $environmentIds)->count(),
                'connections' => $environmentIds->isEmpty() ? 0 : DB::table('connections')
                    ->whereIn('environment_id', $environmentIds)->count(),
                'signIns' => $environmentIds->isEmpty() ? 0 : (int) DB::table('usage_counters')
                    ->whereIn('environment_id', $environmentIds)
                    ->where('metric', UsageMetric::Login->value)
                    ->where('period', '>=', $monthStart)
                    ->sum('count'),
            ],
        ]);
    }
}
