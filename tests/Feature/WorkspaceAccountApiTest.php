<?php

declare(strict_types=1);

use App\Mail\AccountInviteMail;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountApiKeys;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\Models\Account;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

if (! function_exists('apiAccount')) {
    function apiAccount(): Account
    {
        return app(AccountProvisioner::class)->provision(new AccountBlueprint(
            accountName: 'Acme',
            ownerEmail: 'owner@acme.example',
            ownerName: 'Owner',
            ownerPassword: 'supersecret123',
        ))->account;
    }
}

if (! function_exists('issueKey')) {
    function issueKey(Account $account, AccountRole $role): string
    {
        return app(AccountApiKeys::class)->issue($account->id, $role->label().' key', $role)->plaintext;
    }
}

it('serves the account-plane OpenAPI spec publicly', function (): void {
    $this->get('/api/v1/openapi.yaml')
        ->assertOk()
        ->assertHeader('content-type', 'application/yaml')
        ->assertSee('Account Management API');
});

it('rejects requests without a valid account key', function (): void {
    $this->getJson('/api/v1/account')->assertUnauthorized();
    $this->withToken('cbid_acc_totally-bogus-token')->getJson('/api/v1/account')->assertUnauthorized();
});

it('returns the account and plan for a valid key', function (): void {
    $account = apiAccount();
    $token = issueKey($account, AccountRole::Viewer);

    $this->withToken($token)->getJson('/api/v1/account')
        ->assertOk()
        ->assertJsonPath('data.name', 'Acme')
        ->assertJsonPath('data.plan.environment_limit', 2)
        ->assertJsonPath('data.plan.environments_used', 1);
});

it('lists environments for any key but creates only with a manage key', function (): void {
    $account = apiAccount();
    $admin = issueKey($account, AccountRole::Admin);
    $viewer = issueKey($account, AccountRole::Viewer);

    // Read: both roles.
    $this->withToken($viewer)->getJson('/api/v1/account/environments')
        ->assertOk()->assertJsonCount(1, 'data');

    // Create: admin succeeds, and the type is honoured.
    $this->withToken($admin)->postJson('/api/v1/account/environments', ['name' => 'Sandbox', 'type' => 'sandbox'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'sandbox')
        ->assertJsonPath('data.slug', 'acme-sandbox');

    // Create: viewer is forbidden by its role.
    $this->withToken($viewer)->postJson('/api/v1/account/environments', ['name' => 'Nope'])
        ->assertForbidden();
});

it('invites members only with a manage-members key', function (): void {
    Mail::fake();
    $account = apiAccount();
    $admin = issueKey($account, AccountRole::Admin);
    $developer = issueKey($account, AccountRole::Developer);

    $this->withToken($admin)->postJson('/api/v1/account/members', ['email' => 'new@acme.example', 'role' => 'developer'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'invited')
        ->assertJsonPath('data.role', 'developer');

    Mail::assertSent(AccountInviteMail::class);

    // A developer key can't manage members.
    $this->withToken($developer)->postJson('/api/v1/account/members', ['email' => 'x@acme.example', 'role' => 'viewer'])
        ->assertForbidden();
});

it('gates the member roster and billing behind read capability', function (): void {
    $account = apiAccount();
    $developer = issueKey($account, AccountRole::Developer);
    $viewer = issueKey($account, AccountRole::Viewer);

    // A developer/CI key cannot enumerate the roster (PII) or read the plan…
    $this->withToken($developer)->getJson('/api/v1/account/members')->assertForbidden();
    $this->withToken($developer)->getJson('/api/v1/account')->assertOk()->assertJsonMissingPath('data.plan');

    // …a read-only viewer can read both.
    $this->withToken($viewer)->getJson('/api/v1/account/members')->assertOk();
    $this->withToken($viewer)->getJson('/api/v1/account')->assertOk()->assertJsonPath('data.plan.environment_limit', 2);
});

it('enforces the plan limit through the API', function (): void {
    $account = apiAccount();
    $admin = issueKey($account, AccountRole::Admin);

    // Limit 2, one used → one more allowed, then refused.
    $this->withToken($admin)->postJson('/api/v1/account/environments', ['name' => 'Staging'])->assertCreated();
    $this->withToken($admin)->postJson('/api/v1/account/environments', ['name' => 'Extra'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'environment_limit_reached');
});
