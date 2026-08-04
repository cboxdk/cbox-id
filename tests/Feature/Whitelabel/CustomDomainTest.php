<?php

declare(strict_types=1);

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
