<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Enums\GrantPollStatus;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * The environment approvals console had NO coverage, which is how it came to call the
 * CIBA service with a stale signature — an ArgumentCountError nothing exercised — and to
 * offer an Approve button that silently did nothing.
 */
function envApprovalsSetup(): object
{
    platformRootEnvironment();
    $r = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($r->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($r->environment->id));
    actAsEnvironmentAdmin($r->owner->id, $r->environment->id);

    return $r;
}

it('denies a pending agent request from the environment console', function (): void {
    envApprovalsSetup();

    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Agent',
        type: ClientType::Confidential,
        redirectUris: [],
        scopes: ['openid'],
    ));

    $subject = app(Subjects::class)->create('agent-user@acme.example', 'Agent User');

    $pending = app(BackchannelAuthentication::class)
        ->request($client->client, ['openid'], $subject->id);

    Volt::test('environment.approvals')
        ->call('deny', $pending->requestId);

    // Denial is the operator's safe half of the pair: it withholds access.
    expect(BackchannelAuthRequest::query()->whereKey($pending->requestId)->value('status'))
        ->toBe(GrantPollStatus::Denied);
});

/**
 * A CIBA approval is the USER's consent for an agent to act as them, and the token that
 * follows is minted for that user — so an operator must not be able to grant it. The
 * service refuses it (approve() requires the acting subject to BE the request's subject);
 * this asserts the console does not offer it either, so the two cannot drift apart.
 */
it('offers no approve action to an operator', function (): void {
    envApprovalsSetup();

    expect(method_exists(
        Volt::test('environment.approvals')->instance(),
        'approve',
    ))->toBeFalse();
});

/**
 * THE PAGE READ EVERY PENDING REQUEST IN THE ENVIRONMENT, and then asked the client
 * registry for an application name once per row.
 *
 * This is not one person's approvals list — it is the operator's view of the whole
 * environment, and an agent platform produces these continuously. Thirty rows on one
 * screen was thirty-one queries and an unbounded hydration; the number that mattered was
 * whichever one arrived first on a bad day.
 */
it('shows one page of requests and pays a flat number of queries for it', function (): void {
    envApprovalsSetup();

    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Agent',
        type: ClientType::Confidential,
        redirectUris: [],
        scopes: ['openid'],
    ));

    // More than a page, from more than one person, so both lookups have something to
    // batch — a fixture with one subject would pass against a per-row query too.
    foreach (range(1, 30) as $i) {
        $subject = app(Subjects::class)->create("agent-user-{$i}@acme.example", "Agent User {$i}");
        app(BackchannelAuthentication::class)->request($client->client, ['openid'], $subject->id);
    }

    // Warm: the first render of any console page pays for schema and config reads that
    // have nothing to do with this query, and comparing a cold page against a warm one
    // measures the wrong thing entirely.
    Volt::test('environment.approvals')->assertOk();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $page = Volt::test('environment.approvals');

    // The rendered page proves the count is about a full page rather than an empty one:
    // a budget assertion over a page that rendered nothing is the cheapest green there is.
    expect(substr_count($page->html(), 'is requesting access'))->toBe(25)
        ->and($queries)->toBeLessThan(15);
});
