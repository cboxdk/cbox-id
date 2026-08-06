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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** Provision an environment and act as its admin. */
function paginationSetup(): string
{
    platformRootEnvironment();

    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->member, $result->environment->id);

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

    $sql = sqlDuring(fn () => Volt::test('console.audit')->assertOk()->assertSee('seed.event.60'));

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

    $sql = sqlDuring(fn () => Volt::test('environment.users.index')->assertOk());

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

    // Newest first, so page 1 holds the tail and page 2 reaches back.
    Volt::test('console.audit')
        ->assertSee('paged.event.40')
        ->assertDontSee('paged.event.10')
        ->call('setPage', 2)
        ->assertSee('paged.event.10');
});

it('labels a directory row with its organization without loading every organization', function (): void {
    paginationSetup();

    $org = app(Organizations::class)->create(new NewOrganization('Acme Corp', 'acme-corp'));
    app(Directories::class)->register($org->id, 'HR directory');

    // Organizations this page must never load: they own no directory on it.
    foreach (range(1, 5) as $i) {
        app(Organizations::class)->create(new NewOrganization('Other '.$i, 'other-'.$i));
    }

    $sql = sqlDuring(fn () => Volt::test('console.directories.index')->assertOk()->assertSee('Acme Corp'));

    // The label lookup must be constrained to the ids this page actually names. An
    // unbounded `Organization::pluck()` grows with the tenant count and is re-run on
    // every Livewire action.
    $orgReads = array_values(array_filter(
        $sql,
        static fn (string $statement): bool => str_contains($statement, '"organizations"')
            && str_starts_with(strtolower(trim($statement)), 'select'),
    ));

    foreach ($orgReads as $statement) {
        expect($statement)->toContain('"id" in');
    }
});
