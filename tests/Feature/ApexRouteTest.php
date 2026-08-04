<?php

declare(strict_types=1);

use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;

/**
 * `GET /` sends the browser to a door, and which door depends on the plane. It used to
 * work that out for itself, with rules that disagreed with {@see PlaneResolver} — the
 * single source of truth — in two ways at once.
 *
 * The one that reached a 404: multi-tenancy was read as `base_domains !== []`, which is
 * only the compatibility fallback for a deployment that has not stated the mode. State
 * `CBOX_ID_MULTI_TENANT=true` and put every tenant on its own domain — base domains
 * empty, a shape the resolver's own docblock describes — and the apex fell through to
 * `route('login')`, a `plane:subject` route that 404s on the account host. The account
 * console's front door was a 404 on a supported deployment.
 *
 * One test per shape, because the bug was that only one shape was ever exercised.
 */
beforeEach(function (): void {
    installedDeployment();
});

it('sends the apex to the subject sign-in on a single-tenant deployment', function (): void {
    // The suite's pinned shape (see Tests\TestCase): one host, no account door.
    platformRootEnvironment();

    $this->get('/')->assertRedirect(route('login'));
});

it('sends the apex to the account door on the platform-root host of a SUBDOMAIN deployment', function (): void {
    config([
        'cbox-id.tenancy.multi_tenant' => true,
        'cbox-id.tenancy.account_host' => 'cboxid.com',
        'cbox-id.environments.base_domains' => ['cboxid.com'],
    ]);
    platformRootEnvironment();

    $this->get('https://cboxid.com/')->assertRedirect(route('login'));
});

it('sends the apex to the sign-in on a PER-DOMAIN multi-tenant deployment', function (): void {
    // The shape that 404'd: multi-tenancy STATED, tenants on their own domains, so there
    // is no base-domain list to infer the mode from. The apex used to fork on the plane
    // and read multi-tenancy off that empty list, so it fell through to a route the
    // account host did not serve. There is one destination now, which is what makes the
    // shape unrepresentable rather than merely tested.
    config([
        'cbox-id.tenancy.multi_tenant' => true,
        'cbox-id.tenancy.account_host' => 'cboxid.com',
        'cbox-id.environments.base_domains' => [],
    ]);
    platformRootEnvironment();

    $this->get('https://cboxid.com/')->assertRedirect(route('login'));
})->group('security');

it('sends the apex to the tenant sign-in on a tenant host, never the account door', function (): void {
    config([
        'cbox-id.tenancy.multi_tenant' => true,
        'cbox-id.tenancy.account_host' => 'cboxid.com',
        'cbox-id.environments.base_domains' => ['cboxid.com'],
    ]);
    platformRootEnvironment();

    Environment::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active,
        'is_default' => false,
        'settings' => [],
    ]);

    $this->get('https://acme.cboxid.com/')->assertRedirect(route('login'));
});

it('follows the DATABASE platform root, not a stale configured default', function (): void {
    // SetEnvironment returns the `is_default` ROW and falls back to the configured key
    // only when no environment exists. The apex resolved the other way round, so a
    // deployment with both set to different environments had the apex comparing against
    // one root while the request had resolved to another.
    config([
        'cbox-id.tenancy.multi_tenant' => true,
        'cbox-id.tenancy.account_host' => 'cboxid.com',
        'cbox-id.environments.base_domains' => ['cboxid.com'],
        'cbox-id.environments.default' => 'env_not_the_root',
    ]);
    platformRootEnvironment();

    $this->get('https://cboxid.com/')->assertRedirect(route('login'));
})->group('security');
