<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    $r = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    config(['cbox-id.environments.default' => $r->environment->id]);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($r->environment->id));
    session()->put(EnvironmentAdminAuth::SESSION_KEY, $r->member->id);
    session()->put(EnvironmentAdminAuth::ENV_KEY, $r->environment->id);

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
        ->toBe('denied');
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
