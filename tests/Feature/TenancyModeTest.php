<?php

declare(strict_types=1);

use App\Platform\PlaneResolver;

/**
 * Multi-tenancy is a mode you switch on, not something inferred from a domain list.
 *
 * It decides whether the host bulkheads exist at all: in the single-tenant shape every
 * "does this host serve X" question returns true unconditionally. A deployment that had
 * not yet listed its base domains was therefore serving the staff console on every host it
 * answered to — with the config that disabled the bulkheads being a domain list nobody
 * thought of as a security control.
 */
function planes(): PlaneResolver
{
    return app(PlaneResolver::class);
}

it('is multi-tenant when the deployment says so, whatever the domains say', function (): void {
    config()->set('cbox-id.tenancy.multi_tenant', true);
    config()->set('cbox-id.environments.base_domains', []);

    expect(planes()->isMultiTenant())->toBeTrue();
})->group('security');

it('is single-tenant when the deployment says so, whatever the domains say', function (): void {
    // The direction that matters: base domains present, but the operator has NOT turned
    // multi-tenancy on. Inference said "multi-tenant" here; the statement wins.
    config()->set('cbox-id.tenancy.multi_tenant', false);
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);

    expect(planes()->isMultiTenant())->toBeFalse();
})->group('security');

it('falls back to the old derivation when the deployment has not stated it', function (): void {
    // Deliberate, and the reason the default is not simply "single-tenant": flipping an
    // existing multi-tenant install to single-tenant on upgrade would turn its host
    // bulkheads off silently, which is the failure this change exists to prevent.
    config()->set('cbox-id.tenancy.multi_tenant', null);

    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    expect(planes()->isMultiTenant())->toBeTrue();

    config()->set('cbox-id.environments.base_domains', []);
    expect(planes()->isMultiTenant())->toBeFalse();
})->group('security');

it('treats a non-boolean statement as unstated rather than as true', function (): void {
    // An env var arrives as a string. `CBOX_ID_MULTI_TENANT=false` reaching the config as
    // the STRING "false" is truthy in PHP, and a truthy read here would switch a
    // single-tenant install into a shape its host config cannot support. Laravel's env()
    // casts the common literals, but anything it does not recognise must not be guessed.
    foreach (['maybe', '', 0, 1, []] as $nonsense) {
        config()->set('cbox-id.tenancy.multi_tenant', $nonsense);
        config()->set('cbox-id.environments.base_domains', []);

        expect(planes()->isMultiTenant())->toBeFalse();
    }
})->group('security');

it('keeps the host bulkheads on when multi-tenancy is on', function (): void {
    // The consequence, not just the flag. The suite's ambient environment IS the default
    // one, so with the mode on this request is standing on the platform root: no issuer
    // surface, no environment console. With it off there is no root to be on, and every
    // host serves everything.
    config()->set('cbox-id.tenancy.multi_tenant', true);
    expect(planes()->servesIssuer())->toBeFalse()
        ->and(planes()->servesEnvironmentAdmin())->toBeFalse();

    config()->set('cbox-id.tenancy.multi_tenant', false);
    expect(planes()->servesIssuer())->toBeTrue()
        ->and(planes()->servesEnvironmentAdmin())->toBeTrue();
})->group('security');

it('serves multi-tenancy without subdomains, on per-tenant custom domains', function (): void {
    // A real shape: every tenant on its own domain (id.customer.example), resolved by an
    // exact match, and `base_domains` empty because no subdomain resolution is wanted.
    //
    // This state was unreachable while the mode was derived from the domain list —
    // "multi-tenant" implied a base domain existed. Stating the mode made it reachable,
    // and it broke silently: the account host resolved to null, which sent the
    // environment console's sign-in handoff to a local door instead of the account plane,
    // and dropped the account host out of the CSP form-action list so the browser refused
    // cross-plane logout.
    config()->set('cbox-id.tenancy.multi_tenant', true);
    config()->set('cbox-id.tenancy.account_host', 'accounts.example');
    config()->set('cbox-id.environments.base_domains', []);
    config()->set('cbox-id.environments.platform_root_hosts', []);

    expect(planes()->isMultiTenant())->toBeTrue()
        ->and(planes()->consoleHost())->toBe('accounts.example')
        // The consequence that actually broke: cross-plane form posts stay permitted.
        ->and(planes()->formActionHosts())->toContain('accounts.example')
        ->and(planes()->misconfigured())->toBeFalse();
})->group('security');

it('names a multi-tenant deployment with nowhere for the account console to live', function (): void {
    // Not a supported state, and it must not be a quiet one. Two behaviours degrade
    // without an error: the handoff and the content security policy.
    config()->set('cbox-id.tenancy.multi_tenant', true);
    config()->set('cbox-id.tenancy.account_host', null);
    config()->set('cbox-id.environments.base_domains', []);
    config()->set('cbox-id.environments.platform_root_hosts', []);

    expect(planes()->consoleHost())->toBeNull()
        ->and(planes()->misconfigured())->toBeTrue();
})->group('security');

it('is never misconfigured as a single-tenant install', function (): void {
    // Single-tenant has no second plane, so no account host is needed and its absence is
    // the correct answer rather than a fault.
    config()->set('cbox-id.tenancy.multi_tenant', false);
    config()->set('cbox-id.tenancy.account_host', null);
    config()->set('cbox-id.environments.base_domains', []);

    expect(planes()->misconfigured())->toBeFalse();
});

it('still derives the account host from the first base domain when nothing states it', function (): void {
    config()->set('cbox-id.tenancy.multi_tenant', true);
    config()->set('cbox-id.tenancy.account_host', null);
    config()->set('cbox-id.environments.platform_root_hosts', []);
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);

    expect(planes()->consoleHost())->toBe('cboxid.com');
});
