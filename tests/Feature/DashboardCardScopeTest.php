<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Carbon\CarbonInterface;
use Cbox\Console\Kit\Contracts\CurrentContext;
use Cbox\Console\Kit\Contracts\SlotRegistry;
use Cbox\Id\Analytics\Contracts\ReportReader;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Cbox\Id\RiskPlus\Models\RiskEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The dashboard cards, scoped.
 *
 * The console's PAGES had all been narrowed to the acting organization; the stat cards
 * beside them had not. Four modules contributed a card that read the whole environment —
 * 24h sign-in volume, 24h risk events, the audit-export backlog summed across every
 * chain, and (through the console-kit context rather than the scope) a connector count
 * that widened to environment-wide the moment an organization could not be resolved. On a
 * shared multi-tenant environment those are one tenant's traffic and attack signals,
 * rendered on every other tenant's home page.
 *
 * ASSERTED WITHOUT TOUCHING THE MARKUP. A test that greps for a rendered number pins the
 * card's HTML and starts failing on a copy edit, which is how a security assertion gets
 * relaxed by somebody fixing a typo. The property is simpler than the markup anyway:
 * another tenant's activity must not change what this admin sees, and their own must.
 */

/** The dashboard's plugin-card row, rendered exactly as the Blade directive renders it. */
function dashboardCards(): string
{
    return app(SlotRegistry::class)->render('console.dashboard.cards');
}

/** One flagged sign-in for an address. */
function flaggedSignIn(string $email, string $reason): void
{
    RiskEvent::query()->create([
        'action' => 'auth.login',
        'outcome' => 'step_up',
        'score' => 90,
        'reasons' => [$reason],
        'email' => $email,
    ]);
}

/** One entry appended to an organization's audit chain. */
function auditChainEntry(string $organizationId, string $action): void
{
    app(AuditLog::class)->record(new AuditEvent(
        action: $action,
        actorType: ActorType::System,
        actorId: null,
        organizationId: $organizationId,
    ));
}

it('never lets another organization\'s activity move the dashboard cards', function (): void {
    platformRootEnvironment();

    [, $mine] = actingAsRole(MembershipRole::Owner);

    $baseline = dashboardCards();

    // Non-empty, or the two comparisons below would both hold trivially against a row
    // that renders nothing at all.
    expect($baseline)->not->toBe('');

    // ANOTHER TENANT in the same environment, busy: flagged sign-ins against one of their
    // members, and entries piling up in their audit chain waiting to be exported.
    $stranger = app(Subjects::class)->create('stranger@elsewhere.test', 'Stranger', 'a-strong-unbreached-passphrase');
    $theirs = app(Organizations::class)->create(new NewOrganization('Elsewhere', 'elsewhere-co'));
    app(Memberships::class)->add($theirs->id, $stranger->id, MembershipRole::Owner);

    flaggedSignIn('stranger@elsewhere.test', 'credential-stuffing');
    flaggedSignIn('stranger@elsewhere.test', 'impossible-travel');
    auditChainEntry($theirs->id, 'member.added');
    auditChainEntry($theirs->id, 'client.created');

    expect(dashboardCards())->toBe(
        $baseline,
        'A dashboard card moved because ANOTHER organization was attacked or was busy — '
        .'which is exactly the signal a card must not carry across a tenant boundary.',
    );

    // …and the same facts about THIS organization must move it, or the cards are simply
    // dead and the assertion above is worth nothing.
    flaggedSignIn('owner@acme.test', 'credential-stuffing');
    auditChainEntry($mine->id, 'member.added');

    expect(dashboardCards())->not->toBe(
        $baseline,
        'The cards did not react to the acting organization\'s own activity, so the '
        .'isolation assertion above was measuring a dead row.',
    );
})->group('security');

/**
 * The analytics card asked separately, because the sign-in volume it prints comes from a
 * reader the deployment chooses (usage counters, the relational store, ClickHouse) and a
 * test that seeds one store proves nothing about the others. What is asserted here is the
 * whole question instead: `snapshot(null, …)` is documented to total across the
 * ENVIRONMENT, and that literal null was the leak.
 */
it('asks the analytics reader for the acting organization and never for the whole environment', function (): void {
    platformRootEnvironment();

    [, $mine] = actingAsRole(MembershipRole::Owner);

    $reader = new class implements ReportReader
    {
        /** @var list<string|null> */
        public array $asked = [];

        /** @return array<string, int> */
        public function series(string $metric, ?string $organizationId, CarbonInterface $since, CarbonInterface $until): array
        {
            $this->asked[] = $organizationId;

            return [];
        }

        /** @return array<string, int> */
        public function snapshot(?string $organizationId, CarbonInterface $since, CarbonInterface $until): array
        {
            $this->asked[] = $organizationId;

            return ['auth.login' => 4242];
        }

        /** @return array<string, int> */
        public function topOrganizations(string $metric, CarbonInterface $since, CarbonInterface $until, int $limit = 10): array
        {
            return [];
        }
    };

    app()->instance(ReportReader::class, $reader);

    dashboardCards();

    expect($reader->asked)->toBe(
        [$mine->id],
        'The logins card asked the analytics reader for a scope other than the acting '
        .'organization — null totals the whole environment, which is every other tenant\'s '
        .'sign-in volume on this tenant\'s home page.',
    );
})->group('security');

/**
 * The connectors card, which is where the two readings of null actually diverge.
 *
 * It resolved the organization from the console-kit {@see CurrentContext}, whose answer is
 * `CurrentUser::organizationId()` — null both when nobody's organization could be resolved
 * AND when an environment administrator has simply not chosen one. `activeCount(null)` is
 * the ENVIRONMENT-wide overview, so the two meanings collapsed into the widest possible
 * read. {@see ConsoleScope} exists to keep them apart: it throws on the first and returns
 * null only for the second, and a per-organization card answers the second with silence.
 */
it('renders no connectors card until an environment administrator has chosen an organization', function (): void {
    config(['connectors.enabled' => true]);

    platformRootEnvironment();

    $provisioned = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'cards@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    multiTenantDeployment();
    serveOnTestHost($provisioned->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($provisioned->environment->id));
    actAsEnvironmentAdmin($provisioned->member, $provisioned->environment->id);

    // `str_contains` rather than `expect()->toContain()`: Pest reads a second argument to
    // toContain() as ANOTHER NEEDLE, not as a failure message, so a negated call carrying
    // an explanatory string passes as soon as that string is absent — which it always is.
    // This assertion was written that way first and stayed green against the bug.
    expect(str_contains(dashboardCards(), 'Active connectors'))->toBeFalse(
        'The connectors card counted something with no organization resolved — which is '
        .'the whole environment, every tenant included.',
    );

    // …and it comes back the moment there IS an organization to count for, so the
    // assertion above is about scope and not about a card that never renders.
    $organizationId = app(Organizations::class)->create(new NewOrganization('Tenant Co', 'cards-tenant'))->id;
    app(ConsoleScope::class)->chooseOrganization($organizationId);

    expect(str_contains(dashboardCards(), 'Active connectors'))->toBeTrue();
})->group('security');
