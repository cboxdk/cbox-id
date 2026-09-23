<?php

declare(strict_types=1);

use App\Platform\OrganizationActivity;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * KEYS — one page per console, the kind of key as a tab, the tab in the URL.
 *
 * Seven kinds of credential sat on four pages under three names; "API keys" was both the
 * workspace's own keys and half of "Apps & API keys". Now the workspace console has Keys ›
 * Management keys and Keys › Workspace keys, and the environment console has Keys ›
 * Management keys and Keys › Frontend keys.
 */

/** @return list<string> the tab keys a page was drawn with */
function keyTabsOn(string $url): array
{
    return array_column((array) test()->get($url)->assertOk()->inertiaProps('tabs'), 'key');
}

/** An environment administrator on their environment's own host, and what they stand on. */
function anEnvironmentKeyAdmin(): array
{
    multiTenantDeployment();

    $account = provisionAccount('keys-owner@acme.example');

    serveOnTestHost($account['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($account['environment']->id));
    actAsEnvironmentAdmin($account['subjectId'], $account['environment']->id);

    return $account;
}

it('gives the workspace console both of its kinds of key as tabs of one page', function (): void {
    ['subjectId' => $subjectId] = provisionAccount();
    signInAsMember($subjectId);

    expect(keyTabsOn(route('keys')))->toBe(['management', 'workspace'])
        ->and(keyTabsOn(route('keys.workspace')))->toBe(['management', 'workspace']);

    $this->get(route('keys'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('console/keys/management')
        ->where('title', 'Keys')
        ->where('pickEnvironment', true));

    $this->get(route('keys.workspace'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('console/keys/workspace')
        ->where('title', 'Keys'));
});

it('draws only the tabs a person may open', function (): void {
    ['organization' => $workspace] = provisionAccount();
    [, $developerId] = addMember($workspace->id, MembershipRole::Developer, 'dev@acme.example');

    signInAsMember($developerId);

    // A developer mints management keys; the workspace's own keys act with a role across
    // the whole workspace and are a member manager's, so that tab is not drawn — and its
    // URL sends them somewhere they can be rather than to a page that refuses them.
    expect(keyTabsOn(route('keys')))->toBe(['management']);

    $this->get(route('keys.workspace'))->assertRedirect(route('projects'));
});

it('gives the environment console its own management keys and its frontend keys', function (): void {
    ['environment' => $environment] = anEnvironmentKeyAdmin();

    $this->get(route('environment.keys'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/keys/management')
            ->where('title', 'Keys')
            // The environment it stands on, and nothing else — no picker.
            ->where('pickEnvironment', false)
            ->where('environments', [['id' => $environment->id, 'name' => $environment->name]]));

    expect(keyTabsOn(route('environment.keys')))->toBe(['management', 'frontend'])
        ->and(keyTabsOn(route('environment.keys.frontend')))->toBe(['management', 'frontend']);
});

it('mints a management key for the environment the console stands on, on the workspace\'s record', function (): void {
    ['environment' => $environment, 'organization' => $workspace] = anEnvironmentKeyAdmin();

    confirmConsoleStepUp();

    $this->from(route('environment.keys'))
        ->post(route('environment.keys.store'), [
            'environment' => $environment->id,
            'name' => 'Provisioner',
            'scopes' => ['users:read'],
        ])
        ->assertRedirect(route('environment.keys'));

    expect(app(EnvironmentApiKeys::class)->forEnvironment($environment->id)->pluck('name')->all())
        ->toBe(['Provisioner']);

    // Recorded where the workspace console records it — under the workspace, not under
    // whichever of the environment's own organizations the console happens to be acting on.
    expect(app(OrganizationActivity::class)->recent($workspace->id)->pluck('action')->all())
        ->toContain('organization.environment_key_created');
});

it('refuses to mint from one environment\'s console a key for another environment', function (): void {
    ['environment' => $environment, 'project' => $project] = anEnvironmentKeyAdmin();

    // The workspace owns a second environment and this person may reach it — from ITS
    // console, or the workspace's. Not from this one, which administers the host it is on.
    $other = Environment::query()->create([
        'name' => 'Staging', 'slug' => 'keys-staging', 'status' => EnvironmentStatus::Active,
        'project_id' => $project->id, 'is_default' => false,
    ]);

    confirmConsoleStepUp();

    $this->from(route('environment.keys'))
        ->post(route('environment.keys.store'), [
            'environment' => $other->id,
            'name' => 'Sideways',
            'scopes' => ['users:read'],
        ])
        ->assertForbidden();

    expect(app(EnvironmentApiKeys::class)->forEnvironment($other->id))->toBeEmpty()
        ->and(app(EnvironmentApiKeys::class)->forEnvironment($environment->id))->toBeEmpty();
})->group('security');

it('revokes a management key from the environment console', function (): void {
    ['environment' => $environment] = anEnvironmentKeyAdmin();

    $issued = app(EnvironmentApiKeys::class)->issue($environment->id, 'Old', ['users:read']);

    confirmConsoleStepUp();

    $this->from(route('environment.keys'))
        ->delete(route('environment.keys.destroy', $issued->key->id), ['environment' => $environment->id])
        ->assertRedirect(route('environment.keys'));

    expect(app(EnvironmentApiKeys::class)->forEnvironment($environment->id)->firstWhere('id', $issued->key->id)?->revoked_at)
        ->not->toBeNull();
});
