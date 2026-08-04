<?php

declare(strict_types=1);

use App\Mail\AccountInviteMail;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountApiKeys;
use Cbox\Id\Platform\Contracts\Projects;
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

if (! function_exists('apiAccount2')) {
    function apiAccount2(): Account
    {
        return app(AccountProvisioner::class)->provision(new AccountBlueprint(
            accountName: 'Other',
            ownerEmail: 'owner@other.example',
            ownerName: 'Other Owner',
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

it('returns the account and per-project plans for a valid key', function (): void {
    $account = apiAccount();
    $token = issueKey($account, AccountRole::Viewer);

    // Plans are per PROJECT now — the account block lists projects with each one's own
    // allowance, never a single account-level number.
    $this->withToken($token)->getJson('/api/v1/account')
        ->assertOk()
        ->assertJsonPath('data.name', 'Acme')
        ->assertJsonPath('data.projects.0.name', 'Acme')
        ->assertJsonPath('data.projects.0.environment_limit', 2)
        ->assertJsonPath('data.projects.0.environments_used', 1);
});

it('lists and creates projects (IdP products) through the API', function (): void {
    $account = apiAccount();
    $admin = issueKey($account, AccountRole::Admin);
    $viewer = issueKey($account, AccountRole::Viewer);

    // Any key lists projects — the default project is there.
    $this->withToken($viewer)->getJson('/api/v1/account/projects')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Acme');

    // A manage key creates a SECOND, separately-billed product with its own allowance.
    $this->withToken($admin)->postJson('/api/v1/account/projects', ['name' => 'Product Two', 'environment_limit' => 1])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Product Two')
        ->assertJsonPath('data.slug', 'product-two')
        ->assertJsonPath('data.environment_limit', 1);

    // A read-only viewer can't create one.
    $this->withToken($viewer)->postJson('/api/v1/account/projects', ['name' => 'Nope'])->assertForbidden();
});

it('creates an environment under a chosen project and reports its project_id', function (): void {
    $account = apiAccount();
    $admin = issueKey($account, AccountRole::Admin);

    $project = $this->withToken($admin)->postJson('/api/v1/account/projects', ['name' => 'Product Two'])
        ->assertCreated()->json('data.id');

    // Target the second project explicitly; the env comes back tagged with its project.
    $this->withToken($admin)->postJson('/api/v1/account/environments', ['name' => 'Production', 'project_id' => $project])
        ->assertCreated()
        ->assertJsonPath('data.project_id', $project)
        ->assertJsonPath('data.slug', 'product-two');

    // A project_id from another account is refused.
    $other = apiAccount2();
    $otherProject = app(Projects::class)->forAccount($other->id)->first();
    $this->withToken($admin)->postJson('/api/v1/account/environments', ['name' => 'X', 'project_id' => $otherProject->id])
        ->assertNotFound();
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

    // A developer/CI key cannot enumerate the roster (PII) or read the plans…
    $this->withToken($developer)->getJson('/api/v1/account/members')->assertForbidden();
    $this->withToken($developer)->getJson('/api/v1/account')->assertOk()->assertJsonMissingPath('data.projects');

    // …a read-only viewer can read both.
    $this->withToken($viewer)->getJson('/api/v1/account/members')->assertOk();
    $this->withToken($viewer)->getJson('/api/v1/account')->assertOk()->assertJsonPath('data.projects.0.environment_limit', 2);
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
