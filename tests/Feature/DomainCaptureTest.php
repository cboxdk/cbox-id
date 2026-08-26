<?php

declare(strict_types=1);

use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Testing\InteractsWithFederation;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Http;

uses(InteractsWithFederation::class);

// Signup screens the password against HaveIBeenPwned — keep it offline.
beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

it('refuses a password signup for a captured domain and redirects to SSO', function () {
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'cap-yes'));
    $connection = $this->makeConnection($org->id, ConnectionType::Oidc, 'Acme IdP', active: true);
    $domain = $this->makeVerifiedDomain($org->id, 'acme.com');
    app(DomainVerification::class)->setCapture($domain->id, true);

    test()->from(route('signup'))->post(route('signup.register'), ['organization' => 'New Co', 'name' => 'Jane', 'email' => 'jane@acme.com', 'password' => 'a-strong-unbreached-passphrase'])
        ->assertRedirect(url('/sso/oidc/'.$connection->id.'/redirect'));

    // No local account was minted — the capture gate bit before creation.
    expect(app(Subjects::class)->findByEmail('jane@acme.com'))->toBeNull();
});

it('allows a password signup for a verified but non-captured domain', function () {
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'cap-no'));
    // Active connection + verified domain, but capture stays OFF.
    $this->makeConnection($org->id, ConnectionType::Oidc, 'Acme IdP', active: true);
    $this->makeVerifiedDomain($org->id, 'acme.com');

    test()->from(route('signup'))->post(route('signup.register'), ['organization' => 'New Co', 'name' => 'Jane', 'email' => 'jane@acme.com', 'password' => 'a-strong-unbreached-passphrase'])
        ->assertRedirect(route('dashboard'));

    expect(app(Subjects::class)->findByEmail('jane@acme.com'))->not->toBeNull();
});
