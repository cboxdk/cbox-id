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
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Agent requests (OIDC CIBA), as actions: an administrator may see them and DENY one —
| the environment console's Approvals page and an environment key alike. Approving is the
| person's own consent and has no action.
|--------------------------------------------------------------------------
*/

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** A tenant environment served on this test's host, with a pending agent request in it. */
function agentRequestTenant(string $email = 'owner@acme.example'): array
{
    multiTenantDeployment();
    $tenant = provisionAccount($email);
    $environment = $tenant['environment'];

    serveOnTestHost($environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));

    return ['environment' => $environment, 'request' => pendingAgentRequest($environment, 'dana')];
}

function pendingAgentRequest(Environment $environment, string $who): string
{
    return app(EnvironmentContext::class)->runAs($environment, function () use ($who): string {
        $client = app(ClientRegistry::class)->register(new NewClient(name: 'Agent', type: ClientType::Confidential, redirectUris: [], scopes: ['openid']));
        $subject = app(Subjects::class)->create($who.'@acme.example', 'Dana');

        return app(BackchannelAuthentication::class)->request($client->client, ['openid'], $subject->id, bindingMessage: 'Book a flight')->requestId;
    });
}

/**
 * @param  list<string>  $scopes
 */
function agentRequestKey(Environment $environment, array $scopes): string
{
    return app(EnvironmentApiKeys::class)->issue($environment->id, 'Approvals worker', $scopes)->plaintext;
}

it('lists the pending agent requests, with whom each one would act as', function (): void {
    ['environment' => $environment, 'request' => $request] = agentRequestTenant();

    $this->withToken(agentRequestKey($environment, ['approvals:read']))->getJson('/api/v1/agent-requests')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $request)
        ->assertJsonPath('data.0.app', 'Agent')
        ->assertJsonPath('data.0.subject.email', 'dana@acme.example')
        ->assertJsonPath('data.0.binding_message', 'Book a flight')
        ->assertJsonPath('meta.has_more', false);
});

it('denies one for its person, once, and never approves', function (): void {
    ['environment' => $environment, 'request' => $request] = agentRequestTenant();
    $key = agentRequestKey($environment, ['approvals:read', 'approvals:write']);

    $this->withToken($key)->postJson("/api/v1/agent-requests/{$request}/deny")->assertNoContent();

    expect(BackchannelAuthRequest::query()->whereKey($request)->value('status'))->toBe(GrantPollStatus::Denied);

    // Decided: it is no longer pending, so it is not found — not denied twice.
    $this->withToken($key)->postJson("/api/v1/agent-requests/{$request}/deny")->assertNotFound();
    $this->withToken($key)->getJson('/api/v1/agent-requests')->assertJsonCount(0, 'data');

    // There is no approve to call.
    $this->withToken($key)->postJson("/api/v1/agent-requests/{$request}/approve")->assertNotFound();
});

it('needs approvals:write to deny, and approvals:read to look', function (): void {
    ['environment' => $environment, 'request' => $request] = agentRequestTenant();

    $this->withToken(agentRequestKey($environment, ['approvals:read']))->postJson("/api/v1/agent-requests/{$request}/deny")->assertForbidden();
    $this->withToken(agentRequestKey($environment, ['users:read']))->getJson('/api/v1/agent-requests')->assertForbidden();

    expect(BackchannelAuthRequest::query()->whereKey($request)->value('status'))->toBe(GrantPollStatus::Pending);
});

it('answers 404 for another environment\'s request, and leaves it pending', function (): void {
    ['environment' => $environment] = agentRequestTenant();
    $other = provisionAccount('other@other.example')['environment'];
    $theirs = pendingAgentRequest($other, 'theirs');

    $this->withToken(agentRequestKey($environment, ['approvals:write']))->postJson("/api/v1/agent-requests/{$theirs}/deny")->assertNotFound();

    expect(BackchannelAuthRequest::query()->withoutGlobalScopes()->whereKey($theirs)->value('status'))->toBe(GrantPollStatus::Pending);
})->group('security');
