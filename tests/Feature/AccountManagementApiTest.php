<?php

declare(strict_types=1);

use App\Mail\AccountInviteMail;
use App\Platform\OrganizationCapabilities;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

if (! function_exists('apiAccount')) {
    function apiAccount(): Organization
    {
        return app(TenantProvisioner::class)->provision(new TenantBlueprint(
            organizationName: 'Acme',
            ownerEmail: 'owner@acme.example',
            ownerName: 'Owner',
            ownerPassword: 'supersecret123',
        ))->organization;
    }
}

if (! function_exists('apiAccount2')) {
    function apiAccount2(): Organization
    {
        return app(TenantProvisioner::class)->provision(new TenantBlueprint(
            organizationName: 'Other',
            ownerEmail: 'owner@other.example',
            ownerName: 'Other Owner',
            ownerPassword: 'supersecret123',
        ))->organization;
    }
}

if (! function_exists('issueKey')) {
    function issueKey(Account $account, MembershipRole $role): string
    {
        return app(OrganizationApiKeys::class)->issue($account->id, $role->label().' key', $role)->plaintext;
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
    $token = issueKey($account, MembershipRole::Viewer);

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
    $admin = issueKey($account, MembershipRole::Admin);
    $viewer = issueKey($account, MembershipRole::Viewer);

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
    $admin = issueKey($account, MembershipRole::Admin);

    $project = $this->withToken($admin)->postJson('/api/v1/account/projects', ['name' => 'Product Two'])
        ->assertCreated()->json('data.id');

    // Target the second project explicitly; the env comes back tagged with its project.
    $this->withToken($admin)->postJson('/api/v1/account/environments', ['name' => 'Production', 'project_id' => $project])
        ->assertCreated()
        ->assertJsonPath('data.project_id', $project)
        ->assertJsonPath('data.slug', 'product-two');

    // A project_id from another account is refused.
    $other = apiAccount2();
    $otherProject = app(Projects::class)->forOrganization($other->id)->first();
    $this->withToken($admin)->postJson('/api/v1/account/environments', ['name' => 'X', 'project_id' => $otherProject->id])
        ->assertNotFound();
});

it('lists environments for any key but creates only with a manage key', function (): void {
    $account = apiAccount();
    $admin = issueKey($account, MembershipRole::Admin);
    $viewer = issueKey($account, MembershipRole::Viewer);

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
    $admin = issueKey($account, MembershipRole::Admin);
    $developer = issueKey($account, MembershipRole::Developer);

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
    $developer = issueKey($account, MembershipRole::Developer);
    $viewer = issueKey($account, MembershipRole::Viewer);

    // A developer/CI key cannot enumerate the roster (PII) or read the plans…
    $this->withToken($developer)->getJson('/api/v1/account/members')->assertForbidden();
    $this->withToken($developer)->getJson('/api/v1/account')->assertOk()->assertJsonMissingPath('data.projects');

    // …a read-only viewer can read both.
    $this->withToken($viewer)->getJson('/api/v1/account/members')->assertOk();
    $this->withToken($viewer)->getJson('/api/v1/account')->assertOk()->assertJsonPath('data.projects.0.environment_limit', 2);
});

it('enforces the plan limit through the API', function (): void {
    $account = apiAccount();
    $admin = issueKey($account, MembershipRole::Admin);

    // Limit 2, one used → one more allowed, then refused.
    $this->withToken($admin)->postJson('/api/v1/account/environments', ['name' => 'Staging'])->assertCreated();
    $this->withToken($admin)->postJson('/api/v1/account/environments', ['name' => 'Extra'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'environment_limit_reached');
});

/**
 * The machine plane derives capabilities the same way the console does.
 *
 * This middleware was the last place in the app that asked `MembershipRole` directly, and it
 * disagreed with everything else. `OrganizationCapabilities` maps an account role onto the
 * organization plane before answering, and `Billing` maps to `Viewer` — so reading the
 * enum raw gave a `role=billing` key `manage-billing` = true and `read-members` = false,
 * while a human Billing member in the console got the exact opposite on both. One
 * credential type saying yes where the other says no, about the same account, from the
 * same stored role.
 *
 * Nobody holds the role — `MembershipRole::assignable()` stopped offering it precisely
 * because the mapping cannot be made faithful — so this pins the derivation rather than
 * a live exposure: a legacy key that still carries `billing` gets the console's answer,
 * not a second opinion.
 */
it('answers a capability question the same way the console does', function (): void {
    // THROUGH THE MIDDLEWARE, not by asking `OrganizationCapabilities` what it thinks.
    //
    // The first version of this asserted the VO's own answers, which is a test of the VO
    // and passes whether or not the middleware consults it — I removed the middleware's
    // call and it stayed green. The property being claimed is that the machine plane and
    // the console reach the same verdict from the same stored role, and the only way to
    // see that is to make a request.
    //
    // A `billing` key against `read-members`. Reading the raw enum, `MembershipRole::Viewer`
    // refuses it — while a human Billing member in the console is mapped to `Viewer` and
    // may read the roster. Same account, same stored role, opposite answers depending on
    // which credential you held.
    //
    // Nobody can be given the role any more (`assignable()` dropped it, because the
    // mapping cannot be made faithful), so this is a legacy key, and a legacy key getting
    // the console's answer rather than a second opinion is the whole point.
    $account = apiAccount();

    $this->withToken(issueKey($account, MembershipRole::Viewer))
        ->getJson('/api/v1/account/members')
        ->assertOk();
})->group('security');
