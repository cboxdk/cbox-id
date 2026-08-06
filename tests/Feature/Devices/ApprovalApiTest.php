<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Enums\GrantPollStatus;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Two real subjects, so the CIBA login_hint resolves and the subject binding is exercised
 * against genuine ids rather than strings that only look like them.
 */
beforeEach(function (): void {
    $alice = app(Subjects::class)->create('alice@acme.test', 'Alice', 'supersecret123');
    $bob = app(Subjects::class)->create('bob@acme.test', 'Bob', 'supersecret123');

    $this->alice = $alice->id;
    $this->bob = $bob->id;

    $this->app->instance(TokenIntrospector::class, new class($alice->id, $bob->id) implements TokenIntrospector
    {
        public function __construct(private string $alice, private string $bob) {}

        public function introspect(string $token): Introspection
        {
            return match ($token) {
                'alice-tok' => Introspection::active($this->alice, 'cid_authenticator', ['approvals.read', 'approvals.write'], []),
                'bob-tok' => Introspection::active($this->bob, 'cid_authenticator', ['approvals.read', 'approvals.write'], []),
                'readonly-tok' => Introspection::active($this->alice, 'cid_authenticator', ['approvals.read'], []),
                default => Introspection::inactive(),
            };
        }

        public function revoke(string $jti): void {}
    });
});

function approvalAuth(string $token): array
{
    return ['Authorization' => "Bearer {$token}"];
}

function raiseApproval(string $subjectId, ?string $bindingMessage = 'Transfer DKK 4,200 to ACME ApS'): string
{
    $client = app(ClientRegistry::class)->register(
        new NewClient('Acme Agent', ClientType::Confidential, scopes: ['openid'])
    )->client;

    return app(BackchannelAuthentication::class)
        ->request($client, ['openid', 'profile'], $subjectId, $bindingMessage)
        ->requestId;
}

it('lists the caller pending approvals with human scope labels', function (): void {
    raiseApproval($this->alice);

    $this->getJson('/api/v1/approvals', approvalAuth('alice-tok'))
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.client_name', 'Acme Agent')
        ->assertJsonPath('data.0.scopes.0.label', 'Verify your identity');
});

it('never lists another subject approvals', function (): void {
    raiseApproval($this->alice);

    $this->getJson('/api/v1/approvals', approvalAuth('bob-tok'))
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');
});

it('returns the binding message only from the detail endpoint', function (): void {
    $id = raiseApproval($this->alice);

    // This is where the transaction description lives. It is deliberately absent from
    // the push payload, so this call is what the app makes after a notification tap.
    $this->getJson("/api/v1/approvals/{$id}", approvalAuth('alice-tok'))
        ->assertStatus(200)
        ->assertJsonPath('data.binding_message', 'Transfer DKK 4,200 to ACME ApS');
});

it('exposes the internal request id and never the polling secret', function (): void {
    $id = raiseApproval($this->alice);

    $response = $this->getJson("/api/v1/approvals/{$id}", approvalAuth('alice-tok'))->assertStatus(200);

    // auth_req_id is the initiating client's secret; a client able to read it could
    // approve its own request.
    expect($response->json('data.id'))->toBe($id)
        ->and($response->json('data'))->not->toHaveKey('auth_req_id');
});

it('hides another subject approval behind the same 404 as a missing one', function (): void {
    $id = raiseApproval($this->alice);

    $this->getJson("/api/v1/approvals/{$id}", approvalAuth('bob-tok'))
        ->assertStatus(404)
        ->assertJson(['error' => 'not_found', 'message' => 'No such request.']);

    $this->getJson('/api/v1/approvals/01JZZZZZZZZZZZZZZZZZZZZZZZ', approvalAuth('bob-tok'))
        ->assertStatus(404)
        ->assertJson(['error' => 'not_found', 'message' => 'No such request.']);
});

it('approves a pending request', function (): void {
    $id = raiseApproval($this->alice);

    $this->postJson("/api/v1/approvals/{$id}/approve", [], approvalAuth('alice-tok'))
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'approved');

    expect(BackchannelAuthRequest::query()->whereKey($id)->value('status'))->toBe(GrantPollStatus::Approved);
});

it('denies a pending request', function (): void {
    $id = raiseApproval($this->alice);

    $this->postJson("/api/v1/approvals/{$id}/deny", [], approvalAuth('alice-tok'))
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'denied');

    expect(BackchannelAuthRequest::query()->whereKey($id)->value('status'))->toBe(GrantPollStatus::Denied);
});

it('requires the write scope to decide', function (): void {
    $id = raiseApproval($this->alice);

    // A read-only surface — a watch complication, a widget — can show a prompt without
    // carrying the authority to answer it.
    $this->postJson("/api/v1/approvals/{$id}/approve", [], approvalAuth('readonly-tok'))
        ->assertStatus(403)
        ->assertJson(['error' => 'insufficient_scope']);

    expect(BackchannelAuthRequest::query()->whereKey($id)->value('status'))->toBe(GrantPollStatus::Pending);
});

it('collapses every non-actionable outcome into one 409', function (): void {
    $id = raiseApproval($this->alice);

    $this->postJson("/api/v1/approvals/{$id}/approve", [], approvalAuth('alice-tok'))->assertStatus(200);

    // Already answered...
    $repeat = $this->postJson("/api/v1/approvals/{$id}/approve", [], approvalAuth('alice-tok'))
        ->assertStatus(409)
        ->assertJson(['error' => 'approval_not_actionable']);

    // ...and belonging to someone else, and not existing at all, are byte-identical.
    // Distinguishing them would tell a thief holding the handset which ids are real.
    $wrongSubject = $this->postJson("/api/v1/approvals/{$id}/approve", [], approvalAuth('bob-tok'))
        ->assertStatus(409);

    $unknown = $this->postJson('/api/v1/approvals/01JZZZZZZZZZZZZZZZZZZZZZZZ/approve', [], approvalAuth('bob-tok'))
        ->assertStatus(409);

    expect($wrongSubject->json())->toBe($repeat->json())
        ->and($unknown->json())->toBe($repeat->json());
});

it('cannot be used by one subject to approve on behalf of another', function (): void {
    $id = raiseApproval($this->alice);

    $this->postJson("/api/v1/approvals/{$id}/approve", [], approvalAuth('bob-tok'))->assertStatus(409);

    expect(BackchannelAuthRequest::query()->whereKey($id)->value('status'))->toBe(GrantPollStatus::Pending);
});
