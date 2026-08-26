<?php

declare(strict_types=1);

use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** Provision an environment and act as its admin. */
function paginationSetup(): string
{
    // The environment console lives at `/admin`, which 404s unless the deployment is
    // multi-tenant — and the ported pages below are reached by request rather than driven.
    multiTenantDeployment();

    platformRootEnvironment();

    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id);

    return $result->environment->id;
}

/**
 * Every SQL statement issued while rendering a component.
 *
 * @return list<string>
 */
function sqlDuring(Closure $callback): array
{
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $callback();

    return $statements;
}

it('renders the audit feed without counting the whole table', function (): void {
    paginationSetup();

    $audit = app(AuditLog::class);
    foreach (range(1, 60) as $i) {
        $audit->record(new AuditEvent(action: 'seed.event.'.$i, actorType: ActorType::System));
    }

    $sql = sqlDuring(function (): void {
        $actions = collect((array) test()->get(route('environment.audit'))->assertOk()->inertiaProps('entries'))
            ->pluck('action');

        expect($actions)->toContain('seed.event.60');
    });

    // `paginate()` runs a COUNT(*) over the filtered set to render page numbers. On a
    // table with no retention — it only ever grows — that is a full index scan of the
    // environment's whole audit partition, on every render and every keystroke of the
    // debounced filter. `simplePaginate()` answers next/previous from one LIMIT n+1.
    $counts = array_filter(
        $sql,
        static fn (string $statement): bool => str_contains(strtolower($statement), 'count(')
            && str_contains($statement, 'audit_logs'),
    );

    expect($counts)->toBe([]);
});

it('renders the user list without counting the whole table', function (): void {
    paginationSetup();

    $sql = sqlDuring(fn () => test()->get(route('environment.users'))->assertOk());

    // Same reasoning, made worse here by the search being a LEADING wildcard
    // (`LIKE "%term%"`), which no B-tree index can serve at any size.
    $counts = array_filter(
        $sql,
        static fn (string $statement): bool => str_contains(strtolower($statement), 'count(')
            && str_contains($statement, '"users"'),
    );

    expect($counts)->toBe([]);
});

it('still pages through the audit feed', function (): void {
    paginationSetup();

    $audit = app(AuditLog::class);
    foreach (range(1, 40) as $i) {
        $audit->record(new AuditEvent(action: 'paged.event.'.$i, actorType: ActorType::System));
    }

    $actions = fn (array $query = []): Collection => collect(
        (array) test()->get(route('environment.audit', $query))->assertOk()->inertiaProps('entries'),
    )->pluck('action');

    // Newest first, so page 1 holds the tail and page 2 reaches back.
    expect($actions())
        ->toContain('paged.event.40')
        ->not->toContain('paged.event.10')
        ->and($actions(['page' => 2]))->toContain('paged.event.10');
});

it('labels a directory row with its organization without loading every organization', function (): void {
    paginationSetup();

    $org = app(Organizations::class)->create(new NewOrganization('Acme Corp', 'acme-corp'));
    app(Directories::class)->register($org->id, 'HR directory');

    // Organizations this page must never load: they own no directory on it.
    foreach (range(1, 5) as $i) {
        app(Organizations::class)->create(new NewOrganization('Other '.$i, 'other-'.$i));
    }

    $sql = sqlDuring(function (): void {
        $owners = collect((array) test()->get(route('environment.directories'))->assertOk()->inertiaProps('directories'))
            ->pluck('owner');

        expect($owners)->toContain('Acme Corp');
    });

    // The label lookup must be constrained to the ids this page actually names. An
    // unbounded `Organization::pluck()` grows with the tenant count and is re-run on
    // every Livewire action.
    $orgReads = array_values(array_filter(
        $sql,
        static fn (string $statement): bool => str_contains($statement, '"organizations"')
            && str_starts_with(strtolower(trim($statement)), 'select'),
    ));

    // BOUNDED, which is what this is about — not the exact shape of the predicate. An
    // `id in (…)` label lookup is bounded by the rows on the page; so is a `where id = ?`
    // for the ONE acting organization, which the console chrome asks for by name. What must
    // not appear is a read of the table with neither: that grows with the tenant count and
    // is re-run on every Livewire action.
    foreach ($orgReads as $statement) {
        expect(str_contains($statement, '"id" in') || str_contains($statement, '"id" = ?'))
            ->toBeTrue('an unbounded organizations read: '.$statement);
    }
});
