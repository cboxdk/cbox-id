<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\SimplePaginationProps;
use App\Http\Requests\Console\CreateCustomerRequest;
use App\Mail\PasswordResetMail;
use App\Platform\Console\LikeTerm;
use App\Platform\MailLinks;
use App\Platform\OperatorEnvironment;
use Carbon\CarbonInterface;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\Exceptions\EnvironmentLimitReached;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Response;

/**
 * PLATFORM › CUSTOMERS — every customer on the install, and the platform's off-switch for
 * one; then one customer, and everything that hangs off it.
 *
 * A customer is an organization in the PLATFORM ROOT, and organizations are
 * environment-owned. Read under whatever scope the request happened to carry, this page
 * would show a tenant's own end-user organizations — or, off a tenant host, nothing at
 * all. So every read here pins the platform root, and so does every write: a suspension
 * issued from a tenant host would find nothing to suspend and report success.
 *
 * THE COUNTS ARE LINKS. They used to be numbers and nothing else — the list contained no
 * `route()` call at all — so an operator learnt that Acme has three projects and three
 * environments and had nowhere to click. Every count lands on the customer's own page,
 * where organization → project → environment is walkable.
 *
 * WHAT CAN BE CHANGED HERE IS DELIBERATELY NARROW. Suspension is reversible on this same
 * screen and is recorded by {@see Organizations::suspend()} itself rather than at this
 * call site — an audit written at the call site is one a second caller can silently
 * forget. Targeting an environment is a console preference, not a change to anything.
 * Nothing here deletes, purges or reassigns.
 */
final readonly class PlatformCustomerController extends ConsoleController
{
    private const PER_PAGE = 25;

    public function index(Request $request, PlatformRoot $platformRoot): Response
    {
        $this->assertOperator();

        $term = trim($request->string('q')->toString());

        /** @var array{rows: list<array<string, mixed>>, pagination: SimplePaginationProps} $page */
        $page = $platformRoot->run(function () use ($term): array {
            /*
             * ORDERED BY id AS A TIEBREAK, because paging over a non-deterministic sort is
             * not merely untidy: rows created in the same second tie on `created_at`, and
             * the engine is free to order a tie differently per query — so the same
             * customer can appear on two pages, or on neither.
             */
            $query = Organization::query()->orderBy('created_at')->orderBy('id');

            if ($term !== '') {
                /*
                 * GROUPED, not chained. `EnvironmentScope` emits its predicate as a
                 * top-level `where`, so an ungrouped `->where(...)->orWhere(...)` would
                 * compile to `env = X AND name LIKE ? OR slug LIKE ?` and the scope would
                 * stop binding past the OR. Harmless inside this platform-root block; a
                 * closure regardless, because the safety must not depend on where the block
                 * happens to sit.
                 */
                $like = LikeTerm::containing($term);

                $query->where(function (Builder $inner) use ($like): void {
                    $inner->whereRaw($like->sqlFor('name'), [$like->pattern])
                        ->orWhereRaw($like->sqlFor('slug'), [$like->pattern]);
                });
            }

            // simplePaginate rather than paginate: a COUNT(*) over a leading-wildcard LIKE
            // is a full scan no index can serve, re-run on every debounced keystroke, and
            // it buys only page numbers.
            $customers = $query->simplePaginate(self::PER_PAGE)->withQueryString();

            // THE COUNTS ARE SCOPED TO THE PAGE. They used to group over every organization
            // on the install to render 25 rows — three full aggregates per request, growing
            // with the estate.
            $ids = $customers->getCollection()->map(static fn (Organization $o): string => $o->id)->all();

            /*
             * TENANT SCOPE SUSPENDED. A membership is tenant-owned and that scope is
             * deny-by-default, so this roll-up counts ZERO from a request with no tenant in
             * context — silently, and the page would render "0 members" for organizations
             * that have several.
             */
            /** @var Collection<string, int> $memberCounts */
            $memberCounts = app(TenantContext::class)->withoutScope(
                static fn () => Membership::query()->selectRaw('organization_id, count(*) as c')
                    ->whereIn('organization_id', $ids)
                    ->groupBy('organization_id')->pluck('c', 'organization_id'),
            );

            /** @var Collection<string, int> $projectCounts */
            $projectCounts = Project::query()->selectRaw('organization_id, count(*) as c')
                ->whereIn('organization_id', $ids)
                ->groupBy('organization_id')->pluck('c', 'organization_id');

            // Environments hang off PROJECTS, so the count runs through them rather than
            // off a denormalised owner column — `environments.account_id` was that column.
            /** @var Collection<string, int> $environmentCounts */
            $environmentCounts = Environment::query()
                ->join('projects', 'projects.id', '=', 'environments.project_id')
                ->selectRaw('projects.organization_id as organization_id, count(*) as c')
                ->whereIn('projects.organization_id', $ids)
                ->groupBy('projects.organization_id')
                ->pluck('c', 'organization_id');

            $rows = $customers->getCollection()->map(function (Organization $organization) use ($memberCounts, $projectCounts, $environmentCounts): array {
                return [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'active' => ! $organization->status->revokesAccess(),
                    'members' => (int) ($memberCounts[$organization->id] ?? 0),
                    'projects' => (int) ($projectCounts[$organization->id] ?? 0),
                    'environments' => (int) ($environmentCounts[$organization->id] ?? 0),
                    'createdAt' => $this->createdAt($organization),
                    'href' => route('platform.customers.show', $organization->id),
                    'toggleHref' => route('platform.customers.toggle', $organization->id),
                ];
            })->all();

            return ['rows' => array_values($rows), 'pagination' => SimplePaginationProps::from($customers)];
        });

        return $this->page('console/platform/customers', 'Customers', [
            'customers' => $page['rows'],
            'pagination' => $page['pagination'],
            'search' => $term,
            'storeHref' => route('platform.customers.store'),
            'environmentsHref' => route('platform.environments'),
            'environmentLimits' => CreateCustomerRequest::LIMITS,
        ]);
    }

    /**
     * Stand up a whole customer — the organization, its owner, its first IdP product and
     * that product's first environment.
     *
     * THERE WAS NO WAY TO DO THIS. `TenantProvisioner::provision()` is the package's own
     * entry point, exercised by the installer, by signup and by tests, and the operator
     * console — the one surface whose entire job is running the deployment — had no caller
     * for it. An operator could suspend a customer, walk their estate and impersonate their
     * people, and could not create one.
     *
     * `provision()` is one transaction, so a failure leaves no half-born customer. The mail
     * is sent AFTER it returns for the same reason: a reset link for an organization that
     * rolled back is a link to nowhere.
     */
    public function store(
        CreateCustomerRequest $request,
        TenantProvisioner $provisioner,
        PasswordReset $resets,
        MailLinks $links,
    ): RedirectResponse {
        $this->assertOperator();

        $ownerEmail = $request->ownerEmail();

        try {
            $tenant = $provisioner->provision(new TenantBlueprint(
                organizationName: $request->name(),
                ownerEmail: $ownerEmail,
                ownerName: $request->ownerName(),
                // 64 random characters, discarded on the next line. It exists only because
                // the blueprint's contract requires a credential; nothing here can read it
                // back and nobody is ever told it. The owner is sent a reset link and picks
                // their own — an operator who typed a password for somebody else would be
                // an operator who knows a customer's credential.
                ownerPassword: Str::random(64),
                environmentLimit: $request->environmentLimit(),
            ));
        } catch (EnvironmentLimitReached|\InvalidArgumentException $e) {
            // The two refusals a valid form can still hit: a domain already in use, and a
            // deployment with no platform root. Both are sentences worth showing rather than
            // a 500 — the second in particular means "run the installer", which an operator
            // can act on.
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        // The owner's way in. `request()` returns null only for an address with no account,
        // which cannot happen here — provisioning just guaranteed one — so a null means
        // something changed underneath and the operator should be told the customer exists
        // but the invitation did not go.
        $token = $resets->request($ownerEmail);

        if ($token === null) {
            $status = 'Customer created, but the owner could not be sent a link. Ask them to use "Forgot password".';
        } else {
            Mail::to($ownerEmail)->send(new PasswordResetMail($links->route('password.reset', $token)));
            $status = 'Customer created. '.$ownerEmail.' has been emailed a link to set their password.';
        }

        return redirect()->route('platform.customers.show', $tenant->organization->id)->with('status', $status);
    }

    /** One customer, and everything that hangs off it. */
    public function show(string $organization, EnvironmentContext $context, PlatformRoot $platformRoot): Response
    {
        $this->assertOperator();

        $customer = $platformRoot->run(fn (): ?Organization => app(Organizations::class)->find($organization));

        abort_if($customer === null, 404);

        $projects = app(OrganizationProjects::class)->forOrganization($customer->id);
        $projectIds = $projects->pluck('id')->all();

        /*
         * Every environment the customer owns, in ONE read, THROUGH the projects —
         * `environments.account_id` was the shortcut and it is gone. Grouped in PHP rather
         * than queried per project: a five-product customer would otherwise cost five reads
         * to render the same rows.
         */
        $environments = Environment::query()
            ->whereIn('project_id', $projectIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        /*
         * Organizations and users live INSIDE a plane, so these two are the only reads on
         * the page that need the scope escape — and they are grouped, so the page costs the
         * same two queries whether the customer owns one environment or twenty.
         */
        [$orgCounts, $userCounts] = $context->withoutScope(function () use ($environments): array {
            $ids = $environments->modelKeys();

            if ($ids === []) {
                return [[], []];
            }

            /** @var Collection<string, int> $orgs */
            $orgs = Organization::query()->selectRaw('environment_id, count(*) as c')
                ->whereIn('environment_id', $ids)
                ->groupBy('environment_id')->pluck('c', 'environment_id');

            /** @var Collection<string, int> $users */
            $users = User::query()->selectRaw('environment_id, count(*) as c')
                ->whereIn('environment_id', $ids)
                ->groupBy('environment_id')->pluck('c', 'environment_id');

            return [$orgs->all(), $users->all()];
        });

        $activeEnvironmentId = $context->current()?->environmentKey();

        $environmentRow = function (Environment $environment) use ($customer, $orgCounts, $userCounts, $activeEnvironmentId): array {
            return [
                'id' => $environment->id,
                'name' => $environment->name,
                'slug' => $environment->slug,
                'domain' => $environment->domain,
                'sandbox' => $environment->isSandbox(),
                'serving' => $environment->status->canServe(),
                'isTarget' => $environment->id === $activeEnvironmentId,
                'orgs' => (int) ($orgCounts[$environment->id] ?? 0),
                'users' => (int) ($userCounts[$environment->id] ?? 0),
                'targetHref' => route('platform.customers.target', [$customer->id, $environment->id]),
                'openHref' => route('platform.customers.open', [$customer->id, $environment->id]),
            ];
        };

        /** @var array<string, list<array<string, mixed>>> $byProject */
        $byProject = [];
        /** @var list<array<string, mixed>> $unfiled */
        $unfiled = [];

        foreach ($environments as $environment) {
            $projectId = $environment->getAttribute('project_id');

            if (is_string($projectId) && $projectId !== '') {
                $byProject[$projectId][] = $environmentRow($environment);
            } else {
                // An environment the customer owns that sits in no project. The same shape
                // as the install-wide orphan, one level down, and shown for the same reason:
                // a row that exists and is rendered nowhere is a row nobody goes to look at.
                $unfiled[] = $environmentRow($environment);
            }
        }

        $plans = app(Projects::class);

        /*
         * ONE QUERY FOR THE PEOPLE, which is what this page always claimed and did not do:
         * `find()` per membership is one round trip per member of the customer.
         *
         * The whole roster is built INSIDE the platform-root block rather than carried out
         * of it as two values, because `run()` answers null on a deployment with no
         * platform root — and a null that has to be destructured is a fatal on the one
         * shape this page has to survive.
         */
        /** @var array{rows: list<array<string, mixed>>, total: int} $roster */
        $roster = $platformRoot->run(fn (): array => $this->roster($customer->id))
            ?? ['rows' => [], 'total' => 0];

        return $this->page('console/platform/customers/show', $customer->name, [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                // Asked ONCE, here: an organization has no isActive() — the access decision
                // lives on the enum, and the page must not re-derive it.
                'active' => ! $customer->status->revokesAccess(),
                'createdAt' => $this->createdAt($customer),
            ],
            'members' => $roster['rows'],
            'memberTotal' => $roster['total'],
            'projects' => $projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'active' => $project->isActive(),
                'environmentLimit' => $project->environment_limit,
                'remaining' => $plans->remainingEnvironments($project),
                'environments' => $byProject[$project->id] ?? [],
            ])->values()->all(),
            'unfiledEnvironments' => $unfiled,
            'environmentTotal' => $environments->count(),
            'indexHref' => route('platform.customers'),
            'toggleHref' => route('platform.customers.toggle', $customer->id),
        ]);
    }

    /**
     * The customer's team: memberships AND the people behind them.
     *
     * A membership carries authority, not identity, so an operator looking at a roster
     * needs both — and the subjects are hydrated in ONE pass rather than per row.
     *
     * Read inside the platform root by the caller. `name` and `last_login_at` are read off
     * the attribute bag and narrowed: the package's model does not declare them, and
     * trusting an undeclared property is how a column rename becomes a blank column.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    private function roster(string $organizationId): array
    {
        $roster = app(Memberships::class)->paginateForOrganization($organizationId, self::PER_PAGE);

        /** @var list<Membership> $memberships */
        $memberships = collect($roster->items())->values()->all();

        /** @var list<string> $userIds */
        $userIds = array_map(static fn (Membership $m): string => (string) $m->user_id, $memberships);

        $people = $userIds === [] ? [] : app(Subjects::class)->findMany($userIds);

        $rows = array_map(static function (Membership $membership) use ($people): array {
            $name = $membership->getAttribute('name');
            $lastLogin = $membership->getAttribute('last_login_at');

            return [
                'id' => $membership->id,
                'email' => $people[$membership->user_id]->email ?? null,
                'name' => is_string($name) && $name !== '' ? $name : null,
                'role' => $membership->role->label(),
                'status' => $membership->status->value,
                'active' => $membership->status === MembershipStatus::Active,
                'allEnvironments' => $membership->getAttribute('all_environments') === true,
                'lastLogin' => $lastLogin instanceof CarbonInterface ? $lastLogin->toDayDateTimeString() : null,
            ];
        }, $memberships);

        return ['rows' => $rows, 'total' => $roster->total()];
    }

    /**
     * Suspend or reactivate a customer. Idempotent on the contract's side, so the current
     * status decides the direction rather than a separate flag.
     */
    public function toggle(string $organization, Organizations $organizations, PlatformRoot $platformRoot): RedirectResponse
    {
        $this->assertOperator();

        $actorId = $this->scope->operator()?->id;

        abort_if($actorId === null, 403);

        $suspending = $platformRoot->run(function () use ($organizations, $organization, $actorId): ?bool {
            $model = $organizations->find($organization);

            if ($model === null) {
                return null;
            }

            $suspending = ! $model->status->revokesAccess();

            $suspending
                ? $organizations->suspend($model->id, $actorId)
                : $organizations->reactivate($model->id, $actorId);

            return $suspending;
        });

        // 404 rather than a silent return: an id that resolves to nothing is not a button
        // this operator is failing to press, and a redirect back reports success.
        abort_if($suspending === null, 404);

        return back()->with('status', $suspending
            ? 'Customer suspended — its members can no longer sign in and its environments stop serving auth.'
            : 'Customer reactivated.');
    }

    /**
     * Point the console at one of THIS customer's environments and stay here, so the
     * operator can see which plane they are now reading from without losing the customer
     * they were looking at.
     */
    public function target(string $organization, string $environment, OperatorEnvironment $environments): RedirectResponse
    {
        $this->assertOperator();

        $environments->pointAt($this->ownedEnvironment($organization, $environment)->slug);

        return redirect()->route('platform.customers.show', $organization);
    }

    /**
     * Target an environment AND open it — the tenants inside that plane. Two steps that
     * were only ever available as two pages: the flat environments list to switch, then the
     * Organizations entry in the rail to look.
     */
    public function open(string $organization, string $environment, OperatorEnvironment $environments): RedirectResponse
    {
        $this->assertOperator();

        $environments->pointAt($this->ownedEnvironment($organization, $environment)->slug);

        return redirect()->route('platform.organizations');
    }

    /**
     * The environment named by an action, refused unless THIS customer owns it.
     *
     * Deny-by-default rather than "look it up and use it": both actions above take an id
     * straight off the request, and without the ownership predicate either one would let a
     * request made from this page repoint the console at any environment on the install —
     * including the platform root. An operator may legitimately do that from the
     * environments list, which is a different page with a different confirmation; it is not
     * what this page offers, so it is not what this page accepts.
     */
    private function ownedEnvironment(string $organization, string $environment): Environment
    {
        // OWNERSHIP RUNS THROUGH THE PROJECT — `environments.account_id` is gone — and the
        // predicate stays IN the query rather than becoming a comparison afterwards.
        $model = Environment::query()
            ->whereKey($environment)
            ->whereIn('project_id', Project::query()->where('organization_id', $organization)->select('id'))
            ->first();

        abort_if($model === null, 404);

        return $model;
    }

    /**
     * The package's model does not declare the Eloquent timestamp columns as `@property`,
     * so read `created_at` off the attribute bag and narrow it rather than trusting an
     * undeclared property.
     */
    private function createdAt(Organization $organization): ?string
    {
        $createdAt = $organization->getAttribute('created_at');

        return $createdAt instanceof CarbonInterface ? $createdAt->toDayDateTimeString() : null;
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
