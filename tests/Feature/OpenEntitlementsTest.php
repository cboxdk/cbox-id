<?php

declare(strict_types=1);

use App\Platform\Entitlements;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementReader;
use Cbox\Id\Kernel\Authorization\Enums\EnforcementMode;
use Cbox\Id\Kernel\Authorization\Enums\EntitlementSource;
use Cbox\Id\Kernel\Authorization\Testing\InteractsWithEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class, InteractsWithEntitlements::class);

/*
 * The entitlement projection is deny-by-default: a key with no row is denied. That
 * is the right default when a billing plane decides who gets what, and the wrong one
 * for a deployment that has no billing plane and no limits — the self-hosted case,
 * which is now the default.
 */

it('grants an unset entitlement in open mode', function (): void {
    config(['cbox-id.entitlements.mode' => 'open']);

    expect(app(Entitlements::class)->entitled('org_open', 'sso'))->toBeTrue()
        ->and(app(Entitlements::class)->entitled('org_open', 'scim'))->toBeTrue();
});

it('denies an unset entitlement in metered mode', function (): void {
    config(['cbox-id.entitlements.mode' => 'metered']);

    expect(app(Entitlements::class)->entitled('org_metered', 'sso'))->toBeFalse();
});

it('is a floor, not an override: an explicit denial still denies in open mode', function (): void {
    config(['cbox-id.entitlements.mode' => 'open']);

    // The whole point of a floor. A self-hoster with no billing plane can still
    // differentiate organizations by hand, and revoking has to actually revoke —
    // otherwise "open" would mean "ungovernable" rather than "unlimited".
    $this->grantEntitlement('org_revoked', 'cbox-id-sso', ['enabled' => false]);

    expect(app(Entitlements::class)->entitled('org_revoked', 'sso'))->toBeFalse();
});

it('lets an explicit grant win in open mode too', function (): void {
    config(['cbox-id.entitlements.mode' => 'open']);

    $this->grantEntitlement('org_granted', 'cbox-id-sso', ['enabled' => true]);

    expect(app(Entitlements::class)->entitled('org_granted', 'sso'))->toBeTrue();
});

it('marks a synthesised grant as system-sourced and live-checked, never a token claim', function (): void {
    config(['cbox-id.entitlements.mode' => 'open']);

    $value = app(EntitlementReader::class)->get('org_synth', 'anything');

    expect($value)->not->toBeNull()
        ->and($value->bool())->toBeTrue()
        ->and($value->source)->toBe(EntitlementSource::System)
        // DecisionApi, never Claims: a synthesised grant must be resolved live on
        // every check, so it can never be baked into a token that outlives a later
        // decision to start metering.
        ->and($value->mode)->toBe(EnforcementMode::DecisionApi);
});

it('does not fabricate numeric limits', function (): void {
    config(['cbox-id.entitlements.mode' => 'open']);

    $value = app(EntitlementReader::class)->get('org_limits', 'seats');

    // `enabled` and nothing else. Inventing a seat count would hand a caller a
    // number to enforce that nobody ever chose; absent means "no limit was stated".
    expect($value?->bool())->toBeTrue()
        ->and($value?->int('limit'))->toBeNull();
});

it('never synthesises into all(), so tokens carry only real grants', function (): void {
    config(['cbox-id.entitlements.mode' => 'open']);

    $this->grantEntitlement('org_all', 'cbox-id-sso');

    // The token issuer mints the `ent` claim from all(). The key space is open, so
    // there is nothing to enumerate — and passing all() through untouched is what
    // keeps a token honest about what was actually granted.
    expect(array_keys(app(EntitlementReader::class)->all('org_all')))->toBe(['cbox-id-sso']);
});

it('includes identity security in hosted plans while keeping application entitlements closed', function (): void {
    config(['cbox-id.entitlements.mode' => 'inclusive', 'billing.require_subscription' => true]);

    foreach (['sso', 'scim', 'audit_logs'] as $feature) {
        expect(app(Entitlements::class)->entitled('startup', $feature))->toBeTrue();
    }
    expect(app(EntitlementReader::class)->get('startup', 'another-app.paid-feature'))->toBeNull()
        ->and(app(EntitlementReader::class)->all('startup'))->toBe([])
        ->and(app(Entitlements::class)->entitled('', 'sso'))->toBeFalse()
        ->and(app(Entitlements::class)->entitled('startup', 'unknown'))->toBeFalse();
});

it('preserves explicit operational restrictions with inclusive security', function (): void {
    config(['cbox-id.entitlements.mode' => 'inclusive']);
    $this->grantEntitlement('restricted', 'cbox-id-scim', ['enabled' => false]);
    expect(app(Entitlements::class)->entitled('restricted', 'scim'))->toBeFalse()
        ->and(app(Entitlements::class)->entitled('restricted', 'sso'))->toBeTrue();
});

it('uses configured security keys without granting obsolete or empty keys', function (): void {
    config(['cbox-id.entitlements.mode' => 'inclusive', 'cbox-id.entitlements.sso' => 'custom.sso', 'cbox-id.entitlements.scim' => '']);
    $reader = app(EntitlementReader::class);
    expect($reader->get('startup', 'custom.sso')?->bool())->toBeTrue()
        ->and($reader->get('startup', 'cbox-id-sso'))->toBeNull()
        ->and($reader->get('startup', ''))->toBeNull();
});
