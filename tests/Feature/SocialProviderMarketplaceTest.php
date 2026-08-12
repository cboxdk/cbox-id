<?php

declare(strict_types=1);

use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

/**
 * The catalogue turns "describe your provider from memory" into "pick it from a list and
 * paste two things". These cover the parts where getting it wrong is expensive: who may
 * change it, whose credentials get used, and what appears on a sign-in page.
 */

/** Enable a provider directly, the way the page does, without going through the form. */
function enableProvider(string $orgId, string $key, ConnectionType $type = ConnectionType::OAuth2): string
{
    $connections = app(Connections::class);
    $row = $connections->create($orgId, $type, ucfirst($key), [
        'provider' => $key, 'client_id' => 'id', 'client_secret' => 'secret',
    ], provider: $key);
    $connections->activate($orgId, $row->id);

    return $row->id;
}

it('offers the catalogue to an admin', function (): void {
    actingAsRole(MembershipRole::Owner);

    Volt::test('social-providers')
        ->assertSee('GitHub')
        ->assertSee('Discord')
        ->assertSee('Apple')
        // The protocol is shown because it changes what an administrator must go and
        // fetch — an OAuth 2.0 provider has no discovery document to point us at.
        ->assertSee('OpenID Connect')
        ->assertSee('OAuth 2.0');
});

it('refuses the page to a member', function (): void {
    // Enabling a sign-in provider adds a new way into every account in the
    // organization. That is an admin decision, and the page says so server-side rather
    // than by hiding a button.
    actingAsRole(MembershipRole::Member);

    Volt::test('social-providers')->assertForbidden();
})->group('security');

it('refuses to enable a provider the catalogue does not have', function (): void {
    actingAsRole(MembershipRole::Owner);

    // `selected` is #[Locked], so this is the ONLY route in — and it checks. Everything
    // downstream (endpoints, scopes, the profile shape) is looked up by this key.
    Volt::test('social-providers')
        ->call('choose', 'myspace')
        ->assertSet('selected', null);
})->group('security');

it('enables an OAuth 2.0 provider and offers it on the sign-in page', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    Volt::test('social-providers')
        ->call('choose', 'github')
        ->set('clientId', 'gh-client')
        ->set('clientSecret', 'gh-secret')
        ->call('enable')
        ->assertHasNoErrors();

    $enabled = app(Connections::class)->catalogueProvidersFor($org->id);

    expect($enabled)->toHaveCount(1)
        ->and($enabled[0]->provider)->toBe('github')
        ->and($enabled[0]->type)->toBe(ConnectionType::OAuth2)
        // Activated, not left as a draft — a draft renders no button, so an
        // administrator who filled the form in would see nothing happen.
        ->and($enabled[0]->isActive())->toBeTrue();
});

it('does not enable the same provider twice', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    enableProvider($org->id, 'github');

    // Two GitHub buttons with the same label and different credentials is a page nobody
    // can use correctly.
    Volt::test('social-providers')
        ->call('choose', 'github')
        ->set('clientId', 'other')
        ->set('clientSecret', 'other')
        ->call('enable')
        ->assertHasErrors('clientId');

    expect(app(Connections::class)->catalogueProvidersFor($org->id))->toHaveCount(1);
});

it('will not remove another tenant provider by id', function (): void {
    [, $mine] = actingAsRole(MembershipRole::Owner);
    $theirs = enableProvider('someone-elses-org', 'github');

    // The organization is in the QUERY. `byId()` resolves on the primary key alone, so
    // an id used to be all it would take to switch off another organization's sign-in —
    // fenced, until this fix, only by a comparison after the fetch.
    //
    // 404, not 403: another tenant's provider is not a button this administrator is
    // failing to press, it is a row they have no business learning exists. Same refusal
    // the rest of the console gives for a deep link into somebody else's data.
    Volt::test('social-providers')->call('disable', $theirs)->assertStatus(404);

    expect(app(Connections::class)->byId($theirs))->not->toBeNull()
        ->and(app(Connections::class)->catalogueProvidersFor($mine->id))->toBe([]);
})->group('security');

it('removes its own provider', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $id = enableProvider($org->id, 'discord');

    Volt::test('social-providers')->call('disable', $id);

    expect(app(Connections::class)->catalogueProvidersFor($org->id))->toBe([]);
});

it('does not let an unverified account add a sign-in provider', function (): void {
    // Same reasoning as every other create: a provider connection is a durable object
    // other people trust, and an unverified address may belong to somebody else.
    actingAsRole(MembershipRole::Owner, emailVerified: false);

    Volt::test('social-providers')
        ->call('choose', 'github')
        ->set('clientId', 'gh')
        ->set('clientSecret', 'gh')
        ->call('enable')
        ->assertForbidden();
})->group('security');

it('shows a tenant own provider on its branded sign-in page', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    enableProvider($org->id, 'github');
    signOutOfConsole();

    $this->get(route('login.branded', $org->slug))
        ->assertOk()
        ->assertSee('Continue with Github');
});

it('does not show one tenant provider on another sign-in page', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    enableProvider('someone-elses-org', 'github');
    signOutOfConsole();

    // The buttons decide which credentials a sign-in uses, so rendering another
    // tenant's would land the resulting account in the wrong organization.
    $this->get(route('login.branded', $org->slug))
        ->assertOk()
        ->assertDontSee('Continue with Github');
})->group('security');

it('asks for what Apple actually needs, which is not a client secret', function (): void {
    actingAsRole(MembershipRole::Owner);

    // Apple is the entry that does not fit the shape of the others: three parameters,
    // a downloaded signing key rather than a secret, and a Services ID rather than the
    // App ID. A screen that rendered it like GitHub would be asking for things Apple
    // never issues — and the person would go looking for them.
    Volt::test('social-providers')
        ->call('choose', 'apple')
        ->assertSee('Team ID')
        ->assertSee('Key ID')
        ->assertSee('Private key (.p8)')
        ->assertSee('Services ID')
        ->assertSee('There is no client secret to paste')
        // AND NO FIELD FOR ONE. Saying "there is nothing to paste here" above a box
        // labelled "Client secret" is worse than saying nothing: the box is the
        // instruction people follow. This assertion is what stops the field coming back.
        ->assertDontSee('id="clientSecret"', escape: false);
});

it('will not enable a provider whose required parameters are blank', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    // Every missing field is reported at once. One at a time would walk somebody
    // through Apple's three in three round trips.
    Volt::test('social-providers')
        ->call('choose', 'apple')
        ->set('clientId', 'com.acme.service')
        ->set('clientSecret', 'com.acme.service')
        ->call('enable')
        ->assertHasErrors(['parameters.team_id', 'parameters.key_id', 'parameters.private_key']);

    expect(app(Connections::class)->catalogueProvidersFor($org->id))->toBe([]);
});

it('shows the real redirect URI once a provider is enabled', function (): void {
    // Setting one of these up is unavoidably two visits to the provider: the URI contains
    // the connection id, which does not exist until the credentials are saved. So the
    // setup panel can only show a {connection} placeholder — and without this row the one
    // value the provider must be given is available nowhere after saving.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $id = enableProvider($org->id, 'github');

    Volt::test('social-providers')
        ->assertSee('Redirect URI')
        ->assertSee('/sso/oauth2/'.$id.'/callback')
        // The placeholder must not be what an enabled connection shows.
        ->assertDontSee('/sso/oauth2/{connection}/callback');
});

it('uses the OIDC callback path for an OIDC provider', function (): void {
    // The two protocols have different callback routes, and handing Google the OAuth 2.0
    // one produces a redirect_uri_mismatch that names the client id, not the URI.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $id = enableProvider($org->id, 'google', ConnectionType::Oidc);

    Volt::test('social-providers')->assertSee('/sso/oidc/'.$id.'/callback');
});

/**
 * APPLE HAS NO CLIENT SECRET, and the form used to demand one anyway.
 *
 * It relabelled the field "Services ID" — which is the CLIENT ID — so the form asked for
 * the same value twice, once as a password, and stored the second copy as
 * `client_secret`. Whatever the administrator typed there went to Apple's token endpoint
 * as a credential Apple never issued, and the sign-in failed with `invalid_client`: an
 * error that reads as a wrong client id and gets debugged as one.
 */
it('enables Apple without asking for a secret Apple does not issue', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    // Apple's issuer is fixed, so the page runs discovery against it. Faked, because
    // reaching appleid.apple.com from a test is neither reliable nor the thing under
    // test — what is under test is that the form completes with the secret field empty.
    Http::fake(['appleid.apple.com/*' => Http::response([
        'issuer' => 'https://appleid.apple.com',
        'authorization_endpoint' => 'https://appleid.apple.com/auth/authorize',
        'token_endpoint' => 'https://appleid.apple.com/auth/token',
        'jwks_uri' => 'https://appleid.apple.com/auth/keys',
    ])]);

    Volt::test('social-providers')
        ->call('choose', 'apple')
        ->set('clientId', 'com.acme.service')
        ->set('parameters.team_id', 'TEAM123456')
        ->set('parameters.key_id', 'KEY1234567')
        ->set('parameters.private_key', "-----BEGIN PRIVATE KEY-----\nMHc\n-----END PRIVATE KEY-----")
        ->call('enable')
        ->assertHasNoErrors();

    $enabled = app(Connections::class)->catalogueProvidersFor($org->id);

    expect($enabled)->toHaveCount(1)
        ->and($enabled[0]->provider)->toBe('apple');

    // AND NOTHING IS STORED UNDER `client_secret`. An empty string there is not
    // harmless: it is what the token exchange would send, and it is indistinguishable
    // downstream from a secret that was configured and is wrong.
    $config = app(Connections::class)->oidcConfig($enabled[0]);

    expect($config->clientSecret)->toBeNull()
        ->and($config->clientId)->toBe('com.acme.service');
});

it('still requires a client secret from a provider that issues one', function (): void {
    actingAsRole(MembershipRole::Owner);

    Volt::test('social-providers')
        ->call('choose', 'github')
        ->set('clientId', 'gh-client')
        ->call('enable')
        ->assertHasErrors('clientSecret');
});
