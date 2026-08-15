<?php

declare(strict_types=1);

use App\Platform\TrustedHosts;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\DatabaseEnvironmentResolver;
use Cbox\Id\Organization\Models\Environment as EnvironmentModel;
use Cbox\Id\Whitelabel\CustomDomain\Exceptions\InvalidCustomDomain;
use Cbox\Id\Whitelabel\CustomDomain\ManageCustomDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeEnvironment(string $slug = 'prod'): EnvironmentModel
{
    $environment = EnvironmentModel::query()->create(['name' => 'Prod', 'slug' => $slug]);
    app(EnvironmentContext::class)->set($environment);

    return $environment;
}

it('writes environments.domain and round-trips through DatabaseEnvironmentResolver', function (): void {
    $environment = makeEnvironment();

    $stored = app(ManageCustomDomain::class)->set('id.acme.com');

    expect($stored)->toBe('id.acme.com')
        ->and($environment->fresh()?->domain)->toBe('id.acme.com');

    $resolved = (new DatabaseEnvironmentResolver)->resolveForHost('id.acme.com');

    expect($resolved)->not->toBeNull()
        ->and($resolved?->environmentKey())->toBe($environment->id);
});

it('normalizes a pasted URL down to its host', function (): void {
    makeEnvironment();

    expect(app(ManageCustomDomain::class)->set('HTTPS://ID.Acme.com/login'))->toBe('id.acme.com');
});

it('clears the custom domain', function (): void {
    $environment = makeEnvironment();
    $manager = app(ManageCustomDomain::class);

    $manager->set('id.acme.com');
    $manager->clear();

    expect($environment->fresh()?->domain)->toBeNull()
        ->and($manager->current())->toBeNull();
});

it('refuses private, reserved and malformed hosts (SSRF guard reused, not loosened)', function (string $host): void {
    makeEnvironment();

    expect(fn () => app(ManageCustomDomain::class)->set($host))->toThrow(InvalidCustomDomain::class);
})->with([
    'loopback literal' => '127.0.0.1',
    'private literal' => '10.0.0.5',
    'bare hostname' => 'localhost',
    'empty' => '   ',
]);

/**
 * The reservation this had no fence for.
 *
 * `acme.cboxid.com` is how the `acme` environment is ADDRESSED, but it is nobody's
 * `domain` column value, so the uniqueness check above reads it as free. Writing it here
 * would have hijacked that tenant outright, because
 * {@see DatabaseEnvironmentResolver::resolveForHost()} matches `domain` before it resolves
 * a slug — so the victim's own subdomain would have started serving the claimant's
 * environment, branding, sessions and all.
 *
 * Asserted through the RESOLVER as well as the exception: the refusal is only worth
 * anything if the victim still owns the host afterwards.
 */
it('refuses a host under the platform\'s own base domains', function (): void {
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    $victim = EnvironmentModel::query()->create(['name' => 'Victim', 'slug' => 'victim']);
    makeEnvironment('attacker');

    expect(fn () => app(ManageCustomDomain::class)->set('victim.cboxid.com'))
        ->toThrow(InvalidCustomDomain::class);

    // The apex is reserved too — claiming it would take over the platform's own front door.
    expect(fn () => app(ManageCustomDomain::class)->set('cboxid.com'))
        ->toThrow(InvalidCustomDomain::class);

    expect((new DatabaseEnvironmentResolver)->resolveForHost('victim.cboxid.com')?->environmentKey())
        ->toBe($victim->id, 'a tenant subdomain was hijacked by a branding-domain write');
})->group('security');

/**
 * The proof does not travel with the column.
 *
 * `domain_verified_at` is the DNS challenge `EnvironmentDomainService` answered, and two
 * controls read it and nothing else: `TrustedHosts::readCustomDomains()` selects on
 * `whereNotNull('domain_verified_at')` to build the Host allow-list, and the issuer
 * resolver adopts a custom domain as OIDC `iss` on the same condition.
 *
 * This writer promised in its own docblock to leave that stamp null. It wrote one column,
 * so what it left was whatever was already there — and an environment that had verified a
 * host through the proper path was carrying a stamp. Re-pointing the brand moved the
 * domain out from under the proof and the new host inherited it.
 */
it('does not let a re-pointed brand domain inherit the previous domain\'s proof', function (): void {
    $environment = makeEnvironment();

    // The state a whitelabel customer is actually in: one domain, properly verified.
    $environment->forceFill([
        'domain' => 'id.acme.com',
        'domain_verified_at' => now(),
    ])->save();

    app(ManageCustomDomain::class)->set('id.somebody-elses.example');

    $fresh = $environment->fresh();

    expect($fresh?->domain)->toBe('id.somebody-elses.example')
        ->and($fresh?->domain_verified_at)->toBeNull();

    // The two controls that read the stamp, asserted through their own code rather than
    // through the column — the column is the mechanism, this is the consequence.
    expect(in_array('id.somebody-elses.example', app(TrustedHosts::class)->patterns(), true))
        ->toBeFalse('an unproven host reached the trusted-Host allow-list');
})->group('security');

it('takes the proof away with the domain when the brand domain is cleared', function (): void {
    $environment = makeEnvironment();

    $environment->forceFill(['domain' => 'id.acme.com', 'domain_verified_at' => now()])->save();

    app(ManageCustomDomain::class)->clear();

    // A stamp outliving its domain is the same defect one step apart in time: it sits in
    // the row waiting for the next value written into `domain`.
    expect($environment->fresh()?->domain_verified_at)->toBeNull();
})->group('security');
