<?php

declare(strict_types=1);

use App\Assistant\RecordSupportAccess;
use App\Assistant\ServiceProvider;
use App\Platform\NativeProduct\NativeEnvironmentAccess;
use App\Platform\NativeProduct\NativeProductClient;
use App\Platform\NativeProduct\NativeProductManifest;
use App\Platform\OrganizationActivity;
use Cbox\AiAssistant\Connector\Testing\InteractsWithAssistant;
use Cbox\AiAssistant\Connector\ToolRegistry;
use Cbox\Id\AccessControl\Contracts\AppManifests;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Facades\Http;

uses(InteractsWithAssistant::class);

beforeEach(function (): void {
    config(['ai-assistant-connector.support.enabled' => true, 'ai-assistant-connector.support.identity_namespace' => 'cbox-id-platform-root',
        'app.url' => 'https://id.example', 'cbox-id.environments.base_domains' => ['cboxid.example']]);
    new ServiceProvider(app())->boot();
    $this->root = platformRootEnvironment();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    $provisioner = app(TenantProvisioner::class);
    $this->workspace = $provisioner->provision(new TenantBlueprint(organizationName: 'Acme', ownerEmail: 'owner@acme.example', ownerName: 'Owner', ownerPassword: 'support-fixture-12345'));
    $this->other = $provisioner->provision(new TenantBlueprint(organizationName: 'Globex', ownerEmail: 'owner@globex.example', ownerName: 'Other', ownerPassword: 'support-fixture-12345'));
    [$this->billingProject, $this->staging] = app(PlatformRoot::class)->run(function () use ($provisioner): array {
        $project = $provisioner->addProject($this->workspace->organization, 'Billing app');

        return [$project, $provisioner->addEnvironment($project, 'Staging')];
    });
    app(NativeProductClient::class)->synchronize(app(AppManifests::class), new NativeProductManifest);
    $this->fakeAssistantSigning();
    $this->principal = fn (string $subject, ?string $account = null): array => ['sub' => $subject, 'account_id' => $account ?? $this->workspace->organization->id, 'verified' => true,
        'context' => ['service' => 'cbox-id', 'tenant' => $account ?? $this->workspace->organization->id, 'environment' => $this->root->id]];
});

/** A member with the native Reader role, limited to the given environments. */
function profileMember(mixed $test, array $environmentIds): string
{
    [$member, $subject] = addMember($test->workspace->organization->id, MembershipRole::Member, 'scoped-'.count($environmentIds).'@acme.example');
    grantNativeProductRoles($member, ['viewer']);
    $rows = app(NativeEnvironmentAccess::class);
    $rows->replace($member, false, $environmentIds, $rows->version($member));

    return $subject;
}

it('serves the owner every project and environment of their organization with signals', function (): void {
    app(PlatformRoot::class)->run(fn () => $this->staging->forceFill(['status' => EnvironmentStatus::Suspended])->save());
    app(OrganizationActivity::class)->record($this->workspace->organization->id, 'organization.environment_created', $this->workspace->owner->id, 'environment', $this->staging->id, ['name' => 'Staging']);
    app(OrganizationActivity::class)->record($this->workspace->organization->id, 'organization.api_key_created', $this->workspace->owner->id);

    $response = $this->getAssistantProfile(($this->principal)($this->workspace->owner->id))->assertOk();

    $projects = collect($response->json('resources.id_projects.items'))->keyBy('id');
    expect($projects->keys()->sort()->values()->all())->toBe(collect([$this->workspace->project->id, $this->billingProject->id])->sort()->values()->all())
        ->and($projects[$this->billingProject->id])->toMatchArray(['label' => 'Billing app', 'status' => 'ok', 'url' => 'https://id.example/projects/'.$this->billingProject->id])
        ->and($projects[$this->billingProject->id]['metrics'])->toBe(['environments' => 1]);
    $environments = collect($response->json('resources.id_environments.items'))->keyBy('id');
    expect($environments)->toHaveCount(2)
        ->and($environments[$this->staging->id])->toMatchArray(['status' => 'inactive', 'parent' => 'id_projects:'.$this->billingProject->id])
        ->and($environments[$this->staging->id]['attributes']['host'])->toBe($this->staging->slug.'.cboxid.example')
        ->and($environments[$this->staging->id]['metrics'])->toHaveKeys(['users', 'applications'])
        ->and($environments[$this->workspace->environment->id]['parent'])->toBe('id_projects:'.$this->workspace->project->id);
    $served = collect($response->json('resources'))->flatMap(fn (array $resource): array => array_column($resource['items'], 'id'))->all();
    expect($served)->not->toContain($this->other->project->id)
        ->and($served)->not->toContain($this->other->environment->id);

    $response->assertJsonPath('signals.customer_since', $this->workspace->organization->created_at?->format('Y-m-d'))
        ->assertJsonPath('signals.seats', 1)
        ->assertJsonPath('signals.facts.member_role', 'owner')
        ->assertJsonPath('signals.facts.environments', 2)
        ->assertJsonPath('signals.facts.sso_enabled', false)
        ->assertJsonPath('signals.facts.two_factor_enforced', false)
        ->assertJsonPath('signals.recent_changes.0.what', 'Environment created: Staging')
        ->assertJsonPath('signals.recent_changes.0.url', fn (string $url): bool => str_starts_with($url, 'https://id.example/audit?entry='))
        ->assertJsonMissingPath('signals.flags.0');
    expect(array_column($response->json('signals.recent_changes'), 'what'))->not->toContain('organization.api_key_created');

    $this->getAssistantProfile(($this->principal)($this->workspace->owner->id), ['resources' => 'id_environments', 'filter' => ['q' => 'stag']])
        ->assertOk()->assertJsonCount(1, 'resources.id_environments.items')->assertJsonMissingPath('resources.id_projects');
});

it('announces both resources as pickable in the signed manifest', function (): void {
    $resources = collect(app(ToolRegistry::class)->document()['resources'])->keyBy('key');
    expect($resources['id_projects']['pickable'] ?? null)->toBeTrue()->and($resources['id_environments']['pickable'] ?? null)->toBeTrue();
});

it('shares nothing with a principal claiming another organization', function (): void {
    $this->getAssistantProfile(($this->principal)($this->workspace->owner->id, $this->other->organization->id))->assertOk()
        ->assertJsonMissingPath('resources.id_projects')->assertJsonMissingPath('resources.id_environments')
        ->assertJsonPath('signals', []);
    $this->getAssistantProfile([...($this->principal)($this->workspace->owner->id), 'verified' => false])->assertOk()
        ->assertJsonMissingPath('resources.id_projects');
});

it('limits a member to the environments they were granted', function (): void {
    $subject = profileMember($this, [$this->staging->id]);

    $response = $this->getAssistantProfile(($this->principal)($subject))->assertOk();

    expect(array_column($response->json('resources.id_projects.items'), 'id'))->toBe([$this->billingProject->id])
        ->and(array_column($response->json('resources.id_environments.items'), 'id'))->toBe([$this->staging->id]);
    $response->assertJsonPath('signals.facts.member_role', 'member')
        ->assertJsonPath('signals.facts.environments', 1)
        ->assertJsonMissingPath('signals.facts.sso_enabled')
        ->assertJsonMissingPath('signals.recent_changes.0');
});

it('widens a verified support elevation to the same organization only and audits it', function (): void {
    $subject = profileMember($this, [$this->staging->id]);
    $access = $this->assistantSupportAccess(reason: 'Customer cannot sign in to production after a domain change');

    $response = $this->getAssistantProfile(($this->principal)($subject), [], $access)->assertOk();

    expect(collect($response->json('resources.id_projects.items'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->workspace->project->id, $this->billingProject->id])->sort()->values()->all())
        ->and($response->json('resources.id_environments.items'))->toHaveCount(2);
    $response->assertJsonPath('signals.facts.environments', 2)->assertJsonPath('signals.facts.sso_enabled', false);

    $entry = app(PlatformRoot::class)->run(fn () => AuditEntry::query()->where('scope', $this->workspace->organization->id)
        ->where('action', RecordSupportAccess::ACTION)->sole());
    expect($entry->actor_id)->toBe('staff_1')
        ->and($entry->target_id)->toBe($subject)
        ->and($entry->context)->toMatchArray(['staff_name' => 'Support Agent', 'reason' => 'Customer cannot sign in to production after a domain change', 'surface' => 'profile'])
        ->and($entry->context['keys'])->toBe(['id_projects', 'id_environments']);

    // The elevation never crosses into a workspace the customer does not belong to, and writes nothing there.
    $this->getAssistantProfile(($this->principal)($subject, $this->other->organization->id), [], $access)->assertOk()
        ->assertJsonMissingPath('resources.id_projects')->assertJsonPath('signals', []);
    expect(app(PlatformRoot::class)->run(fn () => AuditEntry::query()->where('scope', $this->other->organization->id)
        ->where('action', RecordSupportAccess::ACTION)->exists()))->toBeFalse();
});

it('ignores an elevation without a real reason', function (): void {
    $subject = profileMember($this, [$this->staging->id]);

    $this->getAssistantProfile(($this->principal)($subject), [], $this->assistantSupportAccess(reason: 'because'))->assertOk()
        ->assertJsonCount(1, 'resources.id_environments.items');
    expect(app(PlatformRoot::class)->run(fn () => AuditEntry::query()->where('action', RecordSupportAccess::ACTION)->exists()))->toBeFalse();
});
