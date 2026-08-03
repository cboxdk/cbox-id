<?php

declare(strict_types=1);

use App\Platform\PlaneResolver;

/**
 * Multi-tenancy is a mode you switch on, not something inferred from a domain list.
 *
 * It decides whether the host bulkheads exist at all: in the single-tenant shape both
 * `onSubjectPlane()` and `onOperatorPlane()` return true unconditionally. A deployment
 * that had not yet listed its base domains was therefore serving the staff console on
 * every host it answered to — with the config that disabled the bulkheads being a domain
 * list nobody thought of as a security control.
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
    // The consequence, not just the flag: with the mode on, a host that resolves to
    // nothing is not the subject plane. With it off, every host is.
    config()->set('cbox-id.tenancy.multi_tenant', true);
    expect(planes()->onSubjectPlane())->toBeFalse();

    config()->set('cbox-id.tenancy.multi_tenant', false);
    expect(planes()->onSubjectPlane())->toBeTrue();
})->group('security');
