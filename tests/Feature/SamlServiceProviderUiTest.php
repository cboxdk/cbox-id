<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementWriter;
use Cbox\Id\Kernel\Authorization\Enums\EntitlementSource;
use Cbox\Id\Kernel\Authorization\ValueObjects\EntitlementInput;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\SamlIdp\Contracts\ServiceProviders;
use Cbox\Id\SamlIdp\ValueObjects\NewServiceProvider;
use Livewire\Volt\Volt;

/**
 * Sign an admin (owner) into a fresh org, optionally entitled to SSO. Mirrors the
 * connections/domains UI tests so this file runs in isolation as well as in suite.
 */
function spAdmin(string $slug, bool $entitled = true, string $role = 'owner'): string
{
    $subject = app(Subjects::class)->create("admin@{$slug}.test", 'Admin', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', $slug));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);

    if ($entitled) {
        app(EntitlementWriter::class)->set(
            $org->id,
            new EntitlementInput('cbox-id-sso', ['enabled' => true]),
            EntitlementSource::Manual,
        );
    }

    return $org->id;
}

it('lets an entitled admin register a service provider', function () {
    spAdmin('sp-register');

    Volt::test('sso-providers')
        ->set('entity_id', 'https://saml.salesforce.com')
        ->set('acs_url', 'https://login.salesforce.com/?saml=abc')
        ->set('name_id_attribute', 'email')
        ->set('attribute_mappings', "email = email\ndisplayName = name")
        ->call('create')
        ->assertHasNoErrors();

    $sp = app(ServiceProviders::class)->findByEntityId('https://saml.salesforce.com');

    expect($sp)->not->toBeNull()
        ->and($sp->acs_url)->toBe('https://login.salesforce.com/?saml=abc')
        ->and($sp->attribute_mappings)->toBe(['email' => 'email', 'displayName' => 'name']);
});

it('requires a certificate when signed AuthnRequests are demanded', function () {
    spAdmin('sp-signed-nocert');

    Volt::test('sso-providers')
        ->set('entity_id', 'https://sp.signed/meta')
        ->set('acs_url', 'https://sp.signed/acs')
        ->set('want_authn_requests_signed', true)
        ->call('create')
        ->assertHasErrors('certificate');

    expect(app(ServiceProviders::class)->findByEntityId('https://sp.signed/meta'))->toBeNull();
});

it('lists registered providers and shows the IdP entity id', function () {
    spAdmin('sp-list');
    app(ServiceProviders::class)->register(new NewServiceProvider(
        entityId: 'https://sp.listed/meta',
        acsUrl: 'https://sp.listed/acs',
    ));

    Volt::test('sso-providers')
        ->assertSee('https://sp.listed/meta')
        ->assertSee('/sso/saml/idp/metadata');
});

it('lets an entitled admin remove a provider', function () {
    spAdmin('sp-remove');
    $sp = app(ServiceProviders::class)->register(new NewServiceProvider(
        entityId: 'https://sp.remove/meta',
        acsUrl: 'https://sp.remove/acs',
    ));

    Volt::test('sso-providers')->call('remove', $sp->id)->assertHasNoErrors();

    expect(app(ServiceProviders::class)->findById($sp->id))->toBeNull();
});

it('shows the upsell and refuses every mutating action for a non-entitled org', function () {
    spAdmin('sp-deny', entitled: false);

    Volt::test('sso-providers')->assertSee('Enterprise');

    Volt::test('sso-providers')->call('create')->assertForbidden();
    Volt::test('sso-providers')->call('remove', 'anything')->assertForbidden();
});

it('refuses a non-admin member outright', function () {
    spAdmin('sp-member', role: 'member');

    Volt::test('sso-providers')->assertForbidden();
});

it('refuses a service provider id belonging to another environment', function () {
    // Register an SP in a different plane, then act as an admin in env_test.
    app(EnvironmentContext::class)->set(GenericEnvironment::of('other_plane'));
    $foreign = app(ServiceProviders::class)->register(new NewServiceProvider(
        entityId: 'https://foreign.sp/meta',
        acsUrl: 'https://foreign.sp/acs',
    ));
    app(EnvironmentContext::class)->set(GenericEnvironment::of('env_test'));

    spAdmin('sp-cross');

    // The foreign id never resolves in this environment → deny-by-default 403.
    Volt::test('sso-providers')->call('remove', $foreign->id)->assertForbidden();

    // And it is invisible in the listing.
    app(EnvironmentContext::class)->set(GenericEnvironment::of('env_test'));
    Volt::test('sso-providers')->assertDontSee('https://foreign.sp/meta');
});
