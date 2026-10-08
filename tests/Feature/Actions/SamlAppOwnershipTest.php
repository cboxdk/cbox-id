<?php

declare(strict_types=1);

use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\SamlIdp\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Whose SAML application this is
|--------------------------------------------------------------------------
|
| Since laravel-id 1.22 a SAML application can belong to one organization, and is then only
| ever asserted to that organization's active members. Left without one it is
| environment-wide — anybody with an account in the environment can sign in to it — which
| the console flags. The owner is set the same way from every door.
*/

function samlOwnershipOrg(string $name): Organization
{
    return app(Organizations::class)->create(new NewOrganization($name, str($name)->slug()->append('-saml')->toString()));
}

function samlOwnershipKey(string $environmentId): string
{
    return app(EnvironmentApiKeys::class)->issue($environmentId, 'SAML worker', ['saml_apps:read', 'saml_apps:write'])->plaintext;
}

it('registers a SAML app for one organization over REST, moves it, and opens it to the environment again', function (): void {
    ['envId' => $environmentId] = crudSetup();
    $acme = samlOwnershipOrg('Acme Corp');
    $globex = samlOwnershipOrg('Globex');
    $key = samlOwnershipKey($environmentId);

    $id = $this->withToken($key)->postJson('/api/v1/saml-apps', [
        'entity_id' => 'https://sp.acme.example',
        'acs_url' => 'https://sp.acme.example/acs',
        'organization_id' => $acme->id,
    ])->assertCreated()
        ->assertJsonPath('data.organization_id', $acme->id)
        ->json('data.id');

    expect(ServiceProvider::query()->whereKey($id)->value('organization_id'))->toBe($acme->id)
        ->and(AuditEntry::query()->where('action', 'saml_app.registered')->latest('sequence')->first()?->context['organization_id'] ?? null)->toBe($acme->id);

    // Left out of a change, the owner stays.
    $this->withToken($key)->patchJson("/api/v1/saml-apps/{$id}", ['name_id_attribute' => 'sub'])
        ->assertOk()
        ->assertJsonPath('data.organization_id', $acme->id);

    $this->withToken($key)->patchJson("/api/v1/saml-apps/{$id}", ['organization_id' => $globex->id])
        ->assertOk()
        ->assertJsonPath('data.organization_id', $globex->id);

    // Null, sent, is the environment-wide answer.
    $this->withToken($key)->patchJson("/api/v1/saml-apps/{$id}", ['organization_id' => null])
        ->assertOk()
        ->assertJsonPath('data.organization_id', null);

    expect(ServiceProvider::query()->whereKey($id)->value('organization_id'))->toBeNull();
});

it('refuses an organization that is not in this environment', function (): void {
    ['envId' => $environmentId] = crudSetup();

    $this->withToken(samlOwnershipKey($environmentId))->postJson('/api/v1/saml-apps', [
        'entity_id' => 'https://sp.nowhere.example',
        'acs_url' => 'https://sp.nowhere.example/acs',
        'organization_id' => '01JNOTANORGANIZATIONHERE00',
    ])->assertUnprocessable()->assertJsonPath('error', 'organization_not_found');

    expect(ServiceProvider::query()->count())->toBe(0);
});

it('sets and clears the owning organization from the console, and the list says whose each app is', function (): void {
    crudSetup();
    $acme = samlOwnershipOrg('Acme Corp');

    $form = [
        'entityId' => 'https://console-sp.example.com',
        'acsUrl' => 'https://console-sp.example.com/acs',
        'nameIdFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
        'nameIdAttribute' => 'email',
        'attributeMappings' => [['key' => 'email', 'value' => 'email']],
        'wantAuthnRequestsSigned' => false,
        'organizationId' => $acme->id,
    ];

    test()->post(route('environment.sso-providers.store'), $form)->assertSessionHasNoErrors()->assertRedirect();

    $provider = ServiceProvider::query()->firstOrFail();

    expect($provider->organization_id)->toBe($acme->id);

    $options = collect((array) test()->get(route('environment.sso-providers.show', $provider->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('provider.organizationId', $acme->id))
        ->inertiaProps('organizations'));

    // The environment-wide answer is a choice offered first, not what is left by not looking.
    expect($options->first())->toMatchArray(['value' => '', 'label' => 'Every organization (environment-wide)'])
        ->and($options->pluck('label'))->toContain('Acme Corp');

    test()->get(route('environment.sso-providers'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('providers.0.organization', 'Acme Corp'));

    // Cleared on the edit form: environment-wide again, and the list flags it.
    test()->patch(route('environment.sso-providers.update', $provider->id), [...$form, 'organizationId' => ''])
        ->assertSessionHasNoErrors();

    expect($provider->refresh()->organization_id)->toBeNull();

    test()->get(route('environment.sso-providers'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('providers.0.organization', null));
});
