<?php

declare(strict_types=1);

use App\Platform\OrganizationActivity;
use Cbox\Id\Federation\Contracts\DnsResolver;
use Cbox\Id\Federation\Testing\FakeDnsResolver;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Contracts\EnvironmentDomains;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\OrganizationApiKey;

/*
|--------------------------------------------------------------------------
| A workspace environment's custom domain, as actions: the workspace console's Environment
| domains page and a workspace key, one rule and one trail.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    $this->dns = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $this->dns);
    app()->forgetInstance(EnvironmentDomains::class);
});

/**
 * @param  list<string>|null  $scopes
 */
function domainKey(string $workspaceId, ?array $scopes = ['environments:write'], MembershipRole $role = MembershipRole::Admin): string
{
    return app(OrganizationApiKeys::class)->issue($workspaceId, 'Domains agent', $role, null, $scopes)->plaintext;
}

/** The newest $action on a workspace's own log. */
function domainLog(string $workspaceId, string $action): ?AuditEntry
{
    return app(OrganizationActivity::class)->recent($workspaceId)->first(static fn (AuditEntry $entry): bool => $entry->action === $action);
}

it('serves an environment on a custom domain from a workspace key, recorded as the key', function (): void {
    ['organization' => $workspace, 'environment' => $environment] = provisionAccount();
    $key = domainKey($workspace->id);
    $keyId = OrganizationApiKey::query()->where('organization_id', $workspace->id)->sole()->id;
    $base = "/api/v1/workspace/environments/{$environment->id}/domain";

    $pending = $this->withToken($key)->postJson($base, ['domain' => 'id.acme.com'])
        ->assertOk()
        ->assertJsonPath('data.domain', null)
        ->assertJsonPath('data.pending.domain', 'id.acme.com')
        ->assertJsonPath('data.pending.record_name', '_cbox-id-challenge.id.acme.com');

    // Not visible yet: a refusal that says to wait, and nothing promoted.
    $this->withToken($key)->postJson("{$base}/verify")
        ->assertUnprocessable()
        ->assertJsonPath('error', 'dns_not_propagated');

    $this->dns->publish($pending->json('data.pending.record_name'), $pending->json('data.pending.record_value'));

    $this->withToken($key)->postJson("{$base}/verify")
        ->assertOk()
        ->assertJsonPath('data.domain', 'id.acme.com')
        ->assertJsonPath('data.pending', null);

    $verified = domainLog($workspace->id, 'organization.custom_domain_verified');

    expect($environment->fresh()->domain)->toBe('id.acme.com')
        ->and($verified?->actor_type)->toBe(ActorType::Service)
        ->and($verified?->actor_id)->toBe($keyId)
        ->and($verified?->target_id)->toBe($environment->id)
        ->and($verified?->context['domain'] ?? null)->toBe('id.acme.com');

    $this->withToken($key)->deleteJson($base)->assertNoContent();

    expect($environment->fresh()->domain)->toBeNull()
        ->and(domainLog($workspace->id, 'organization.custom_domain_removed')?->actor_id)->toBe($keyId);

    // A platform domain is refused with the service's own sentence, on the field.
    $this->withToken($key)->postJson($base, ['domain' => 'acme.cboxid.com'])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_domain');
});

it('records the console\'s change the same way, as the workspace member', function (): void {
    ['organization' => $workspace, 'subjectId' => $owner, 'environment' => $environment] = provisionAccount();
    $environment->update(['domain' => 'id.acme.com']);
    signInAsMember($owner);

    test()->from(route('environment-domains', ['environment' => $environment->id]))
        ->delete(route('environment-domains.destroy'), ['environment' => $environment->id])
        ->assertSessionHasNoErrors();

    $entry = domainLog($workspace->id, 'organization.custom_domain_removed');

    expect($environment->fresh()->domain)->toBeNull()
        ->and($entry?->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($entry?->actor_id)->toBe($owner);
});

it('answers 404 for another workspace\'s environment, and changes nothing there', function (): void {
    $mine = provisionAccount('mine@acme.example');
    $theirs = provisionAccount('theirs@other.example');
    app(EnvironmentDomains::class)->request($theirs['environment']->id, 'id.other.example');

    $key = domainKey($mine['organization']->id);
    $base = "/api/v1/workspace/environments/{$theirs['environment']->id}/domain";

    $this->withToken($key)->postJson($base, ['domain' => 'id.mine.example'])->assertNotFound();
    $this->withToken($key)->postJson("{$base}/verify")->assertNotFound();
    $this->withToken($key)->deleteJson($base)->assertNotFound();

    expect(app(EnvironmentDomains::class)->challenge($theirs['environment']->id)?->domain)->toBe('id.other.example');
})->group('security');

it('needs environments:write and a role that may manage environments', function (): void {
    ['organization' => $workspace, 'environment' => $environment] = provisionAccount();
    $base = "/api/v1/workspace/environments/{$environment->id}/domain";

    $this->withToken(domainKey($workspace->id, ['workspace:read']))->postJson($base, ['domain' => 'id.acme.com'])->assertForbidden();
    $this->withToken(domainKey($workspace->id, null, MembershipRole::Viewer))->postJson($base, ['domain' => 'id.acme.com'])->assertForbidden();

    expect(app(EnvironmentDomains::class)->challenge($environment->id))->toBeNull();
});

it('replays a retried request with the same Idempotency-Key', function (): void {
    ['organization' => $workspace, 'environment' => $environment] = provisionAccount();
    $key = domainKey($workspace->id);

    $first = $this->withToken($key)->withHeader('Idempotency-Key', 'dom-1')
        ->postJson("/api/v1/workspace/environments/{$environment->id}/domain", ['domain' => 'id.acme.com'])->assertOk();
    $again = $this->withToken($key)->withHeader('Idempotency-Key', 'dom-1')
        ->postJson("/api/v1/workspace/environments/{$environment->id}/domain", ['domain' => 'id.acme.com'])->assertOk();

    expect($again->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($again->json('data.pending.record_value'))->toBe($first->json('data.pending.record_value'));
});
