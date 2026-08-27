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

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * The environment approvals console had NO coverage, which is how it came to call the
 * CIBA service with a stale signature — an ArgumentCountError nothing exercised — and to
 * offer an Approve button that silently did nothing.
 */
function envApprovalsSetup(): object
{
    // `/admin` exists only on the multi-tenant shape — the page is reached by REQUEST now
    // rather than driven directly.
    multiTenantDeployment();

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

    test()->from(route('environment.approvals'))
        ->post(route('environment.approvals.deny', $pending->requestId))
        ->assertRedirect(route('environment.approvals'));

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

    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Agent',
        type: ClientType::Confidential,
        redirectUris: [],
        scopes: ['openid'],
    ));

    $subject = app(Subjects::class)->create('no-approve@acme.example', 'Subject');
    $pending = app(BackchannelAuthentication::class)->request($client->client, ['openid'], $subject->id);

    /*
     * ASKED FOR, not grepped. "There is no approve method" was a claim about one class;
     * the claim that matters is that no ROUTE grants an operator's approval — a second
     * controller, or a route added under another name, would satisfy the old test and
     * still hand out consent nobody gave.
     *
     * Both spellings the deny endpoint suggests, and the state afterwards: a refusal
     * status over a request that had nonetheless been approved would pass on status alone.
     */
    test()->post('/admin/approvals/'.$pending->requestId.'/approve')->assertNotFound();
    test()->post('/admin/approvals/'.$pending->requestId)->assertNotFound();

    expect(BackchannelAuthRequest::query()->whereKey($pending->requestId)->value('status'))
        ->toBe(GrantPollStatus::Pending);

    // …and the page does not offer one either, so the two cannot drift apart.
    $rows = (array) test()->get(route('environment.approvals'))->assertOk()->inertiaProps('requests');

    expect($rows)->toHaveCount(1)
        ->and(array_keys((array) $rows[0]))->not->toContain('approveHref');
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
    test()->get(route('environment.approvals'))->assertOk();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $rows = (array) test()->get(route('environment.approvals'))->assertOk()->inertiaProps('requests');

    // The page of rows proves the budget is about a FULL page rather than an empty one: a
    // query-count assertion over a page that rendered nothing is the cheapest green there
    // is. And every row carries a resolved name, which is what the per-row query bought.
    expect($rows)->toHaveCount(25)
        ->and(collect($rows)->pluck('app')->unique()->all())->toBe(['Agent'])
        ->and($queries)->toBeLessThan(15);
});
