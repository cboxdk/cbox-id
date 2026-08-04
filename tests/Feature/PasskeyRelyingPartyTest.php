<?php

declare(strict_types=1);

use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;

/**
 * The passkey ceremony's Relying Party follows the HOST, over HTTP, on every shape this
 * platform serves.
 *
 * `rp_id` and `origin` were one deployment-wide pair bound from static config, defaulting
 * to APP_URL's host. With the account root at cboxid.com that made the account plane work
 * and EVERY tenant fail: the browser on `acme.cboxid.com` reports that origin, the
 * verifier compared it byte-for-byte against `https://cboxid.com`, and the enrolment came
 * back "origin mismatch". A verified custom domain failed twice over — `cboxid.com` is
 * not a registrable suffix of `id.acme.com`, so the challenge itself was unusable before
 * any verification ran.
 *
 * The subject-plane routes are `plane:subject` (tenant hosts) and the account-plane ones
 * `plane:account` (root host only), so no single value could ever have satisfied both.
 * These assert the challenge END of the ceremony — the rp_id the server tells the browser
 * to scope the credential to — because that is what the verifier will then be checked
 * against, and the two coming from one resolver is the fix. The framework suite drives
 * the cryptographic half against a software authenticator.
 */
beforeEach(function (): void {
    // The SaaS shape, stated — the suite is pinned single-tenant (see Tests\TestCase).
    config([
        'cbox-id.tenancy.multi_tenant' => true,
        'cbox-id.tenancy.account_host' => 'cboxid.com',
        'cbox-id.environments.base_domains' => ['cboxid.com'],
        'cbox-id.issuer' => 'https://cboxid.com',
        // No pin: the state every deployment should be in, and the one the fix is about.
        'cbox-id.webauthn.rp_id' => null,
        'cbox-id.webauthn.origin' => null,
    ]);

    installedDeployment();
    platformRootEnvironment();
});

function passkeyTenantEnvironment(string $slug, ?string $domain = null): Environment
{
    return Environment::query()->create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active,
        'is_default' => false,
        'domain' => $domain,
        'domain_verified_at' => $domain === null ? null : now(),
        'settings' => [],
    ]);
}

it('scopes a tenant subdomain ceremony to that subdomain', function (): void {
    passkeyTenantEnvironment('acme');

    $this->postJson('https://acme.cboxid.com/passkeys/login/options')
        ->assertOk()
        ->assertJsonPath('rpId', 'acme.cboxid.com');
})->group('security');

it('scopes a verified custom domain ceremony to that domain', function (): void {
    passkeyTenantEnvironment('globex', 'id.globex.com');

    $this->postJson('https://id.globex.com/passkeys/login/options')
        ->assertOk()
        ->assertJsonPath('rpId', 'id.globex.com');
})->group('security');

it('keeps two tenants on separate Relying Parties, so neither is offered the other credential', function (): void {
    // The reason the RP id is the environment's FULL host and not the base domain it sits
    // under: `cboxid.com` would satisfy WebAuthn for both, and offer one tenant's passkey
    // on the other's sign-in page.
    passkeyTenantEnvironment('acme');
    passkeyTenantEnvironment('globex');

    $acme = $this->postJson('https://acme.cboxid.com/passkeys/login/options')->assertOk();
    $globex = $this->postJson('https://globex.cboxid.com/passkeys/login/options')->assertOk();

    expect($acme->json('rpId'))->toBe('acme.cboxid.com')
        ->and($globex->json('rpId'))->toBe('globex.cboxid.com')
        ->and($acme->json('rpId'))->not->toBe($globex->json('rpId'));
})->group('security');

it('keeps the account plane on the platform root, which is the half that already worked', function (): void {
    passkeyTenantEnvironment('acme');

    $this->postJson('https://cboxid.com/passkeys/login/options')
        ->assertOk()
        ->assertJsonPath('rpId', 'cboxid.com');
})->group('security');
