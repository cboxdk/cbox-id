<?php

declare(strict_types=1);

use App\Platform\Actions\Approvals\ActionApprovalGate;
use App\Platform\Actions\Approvals\ActionApprovalRequest;
use App\Platform\Actions\Idempotency\IdempotencyRecord;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Keys\OrganizationKeyPrefixes;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * v3 renamed the workspace to the organization in what is STORED, and ID is live: every
 * row written by v2 must still mean the same thing after the migration runs. Each test
 * writes the v2 shape, runs the migration again (it is idempotent: the suite already ran
 * it on an empty database) and asks the v3 code the v2 question.
 */
beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

function runRenameMigration(): void
{
    $migration = require database_path('migrations/2026_11_10_130000_rename_workspace_to_organization.php');
    $migration->up();
}

/** A v2 `cbid_ws_` key, written the way v2's framework wrote it: the hash of the whole plaintext. */
function legacyWorkspaceKey(string $organizationId, ?array $scopes = null, array $attributes = []): array
{
    $plaintext = OrganizationKeyPrefixes::LEGACY.Str::random(40);
    $key = OrganizationApiKey::query()->create([
        'organization_id' => $organizationId,
        'name' => 'v2 agent',
        'prefix' => substr($plaintext, 0, strlen(OrganizationKeyPrefixes::LEGACY) + 3),
        'token_hash' => hash('sha256', $plaintext),
        'role' => MembershipRole::Admin,
        'scopes' => $scopes,
        ...$attributes,
    ]);

    return [$key, $plaintext];
}

it('renames workspace_key provenance in both key tables, and the approver chain still resolves', function (): void {
    $account = provisionAccount();
    $person = $account['subjectId'];

    $minter = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Minter', MembershipRole::Admin, null, null, new KeyProvenance(
        createdByType: 'organization_member',
        createdById: $person,
    ))->key;
    $child = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Child', MembershipRole::Developer)->key;
    $environmentKey = $this->withToken(app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Mint', MembershipRole::Admin)->plaintext)
        ->postJson("/api/v1/workspace/environments/{$account['environment']->id}/keys", ['name' => 'CI', 'scopes' => ['apis:read']])
        ->assertCreated()->json('data.id');

    // What v2 wrote: both kinds of key minted by a workspace key say `workspace_key`.
    DB::table('organization_api_keys')->where('id', $child->id)->update(['created_by_type' => 'workspace_key', 'created_by_id' => $minter->id]);
    DB::table('environment_api_keys')->where('id', $environmentKey)->update(['created_by_type' => 'workspace_key', 'created_by_id' => $minter->id]);

    runRenameMigration();

    $environment = app(PlatformRoot::class)->run(fn () => EnvironmentApiKey::query()->withoutGlobalScopes()->findOrFail($environmentKey));

    expect(DB::table('organization_api_keys')->where('id', $child->id)->value('created_by_type'))->toBe('organization_key')
        ->and($environment->created_by_type)->toBe('organization_key')
        ->and(DB::table('organization_api_keys')->where('created_by_type', 'workspace_key')->exists())->toBeFalse()
        ->and(DB::table('environment_api_keys')->where('created_by_type', 'workspace_key')->exists())->toBeFalse()
        // The environment key's held actions are still answered by the person behind the key that minted it.
        ->and((new EnvironmentKeyPrincipal($environment))->approverSubjectId())->toBe($person);
});

it('mints cbid_org_ keys, and still takes a v2 cbid_ws_ key with a Deprecation header', function (): void {
    $account = provisionAccount();

    $fresh = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Fresh', MembershipRole::Admin)->plaintext;
    [, $legacy] = legacyWorkspaceKey($account['organization']->id);

    expect($fresh)->toStartWith('cbid_org_');

    $this->withToken($fresh)->getJson('/api/v1/workspace')->assertOk()->assertHeaderMissing('Deprecation');
    $this->withToken($legacy)->getJson('/api/v1/workspace')
        ->assertOk()
        ->assertJsonPath('data.id', $account['organization']->id)
        ->assertHeader('Deprecation', OrganizationKeyPrefixes::DEPRECATED_AT);
})->group('security');

it('keeps a revoked 1.x cbid_org_ key revoked now that the prefix is minted again', function (): void {
    $account = provisionAccount();
    $plaintext = 'cbid_org_'.Str::random(40);
    OrganizationApiKey::query()->create([
        'organization_id' => $account['organization']->id,
        'name' => '1.x key',
        'prefix' => substr($plaintext, 0, 12),
        'token_hash' => hash('sha256', $plaintext),
        'role' => MembershipRole::Owner,
        'revoked_at' => now()->subMonth(),
    ]);

    expect(app(OrganizationApiKeys::class)->resolve($plaintext))->toBeNull();
    $this->withToken($plaintext)->getJson('/api/v1/workspace')->assertUnauthorized();
})->group('security');

it('rewrites an organization key\'s stored scopes in the new names, and a v2 name still reaches the read', function (): void {
    $account = provisionAccount();
    [$key, $legacy] = legacyWorkspaceKey($account['organization']->id, ['workspace:read', 'keys:write']);

    // Before the rewrite, the old name on the key satisfies the new name on the route.
    $this->withToken($legacy)->getJson('/api/v1/workspace')->assertOk();

    runRenameMigration();

    expect(OrganizationApiKey::query()->findOrFail($key->id)->scopes)
        ->toBe(['id:organization:read', 'id:environment-keys:write', 'id:organization-keys:write']);

    $this->withToken($legacy)->getJson('/api/v1/workspace')->assertOk();
    $this->withToken($legacy)->getJson('/api/v1/workspace/members')
        ->assertForbidden()->assertJsonPath('message', 'This key is missing the required scope: id:members:read.');
});

it('replays an idempotent answer stored under the v2 action name and key kind', function (): void {
    $account = provisionAccount();
    $token = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Agent', MembershipRole::Admin);

    $first = $this->withToken($token->plaintext)
        ->postJson('/api/v1/workspace/keys', ['name' => 'Once', 'role' => 'developer'], ['Idempotency-Key' => 'rename-1'])
        ->assertCreated();

    // As v2 stored it: the old action name and owner kind, and the request hashed under the
    // old action name (the migration renames the row, not the hash inside it).
    $record = IdempotencyRecord::query()->where('idempotency_key', 'rename-1')->sole();
    expect($record->action)->toBe('keys.organization.create');
    $hashed = static fn (string $action): string => hash('sha256', $action.'|'.json_encode(['name' => 'Once', 'role' => 'developer'], JSON_THROW_ON_ERROR));
    expect($record->request_hash)->toBe($hashed('keys.organization.create'));
    DB::table('action_idempotency_records')->where('id', $record->id)->update([
        'action' => 'keys.workspace.create',
        'principal' => 'workspace_key:'.$token->key->id,
        'request_hash' => $hashed('keys.workspace.create'),
    ]);

    runRenameMigration();

    expect(DB::table('action_idempotency_records')->where('id', $record->id)->first())
        ->action->toBe('keys.organization.create')
        ->principal->toBe('organization_key:'.$token->key->id);

    $this->withToken($token->plaintext)
        ->postJson('/api/v1/workspace/keys', ['name' => 'Once', 'role' => 'developer'], ['Idempotency-Key' => 'rename-1'])
        ->assertCreated()
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(OrganizationApiKey::query()->where('name', 'Once')->count())->toBe(1);
});

it('spends an approval given under the v2 action name and key kind for the v3 action', function (): void {
    $account = provisionAccount();
    $key = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Supervised', MembershipRole::Admin, null, null, new KeyProvenance(
        createdByType: 'organization_member',
        createdById: $account['subjectId'],
        stepUpPolicy: ['min_danger' => 'critical', 'actions' => []],
    ));
    $body = ['name' => 'Held child', 'role' => 'developer'];

    $approvalId = (string) $this->withToken($key->plaintext)->postJson('/api/v1/workspace/keys', $body)
        ->assertStatus(202)->json('approval.id');

    // Rewind the request to what v2 filed: its row names the old action and owner kind, and
    // the digest the person approves was taken over those old names.
    $stored = ActionApprovalRequest::query()->findOrFail($approvalId);
    $canonical = (new ReflectionMethod(ActionApprovalGate::class, 'canonical'))->invoke(null, (array) $stored->input);
    $digest = static fn (string $kind, string $action): string => hash('sha256', json_encode([$kind.':'.$key->key->id, $action, $canonical], JSON_THROW_ON_ERROR));
    $backchannel = app(PlatformRoot::class)->run(fn () => BackchannelAuthRequest::query()->findOrFail($approvalId));

    expect($backchannel->action_digest)->toBe($digest('organization_key', 'keys.organization.create'));

    app(PlatformRoot::class)->run(fn () => $backchannel->forceFill(['action_digest' => $digest('workspace_key', 'keys.workspace.create')])->save());
    DB::table('action_approval_requests')->where('id', $approvalId)->update(['action' => 'keys.workspace.create', 'principal' => 'workspace_key:'.$key->key->id]);

    runRenameMigration();

    expect(DB::table('action_approval_requests')->where('id', $approvalId)->value('action'))->toBe('keys.organization.create');

    app(PlatformRoot::class)->run(fn () => app(BackchannelAuthentication::class)->approve($approvalId, $account['subjectId']));

    $this->withToken($key->plaintext)->postJson('/api/v1/workspace/keys', $body, ['Cbox-Approval' => $approvalId])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Held child');
})->group('security');

it('leaves the hash-chained audit trail as it was: the chain still verifies', function (): void {
    $account = provisionAccount();
    $token = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Agent', MembershipRole::Owner)->plaintext;
    $this->withToken($token)->patchJson('/api/v1/workspace', ['name' => 'Renamed before v3'])->assertOk();

    $before = DB::table('audit_logs')->count();

    runRenameMigration();

    expect(DB::table('audit_logs')->count())->toBe($before)
        ->and(Artisan::call('audit-chain:verify'))->toBe(0);
});

it('names the billing usage outbox column for what it holds', function (): void {
    expect(Schema::hasColumn('product_billing_usage_outbox', 'organization_id'))->toBeTrue()
        ->and(Schema::hasColumn('product_billing_usage_outbox', 'workspace_id'))->toBeFalse();
});
