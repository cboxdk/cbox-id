<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Federation\Testing\InteractsWithFederation;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementWriter;
use Cbox\Id\Kernel\Authorization\Enums\EntitlementSource;
use Cbox\Id\Kernel\Authorization\ValueObjects\EntitlementInput;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;

uses(InteractsWithFederation::class);

// This file is ABOUT the entitlement gate, so it declares the mode it exercises.
// The default is now `open` — an unset entitlement means granted, which is what a
// self-hosted deployment runs and what most of the suite therefore sees. Gating
// only means anything under `metered`, where the billing projection is the sole
// source of a grant.
beforeEach(function (): void {
    config(['cbox-id.entitlements.mode' => 'metered']);
});

/**
 * Sign an admin (owner) into a fresh org, optionally entitled to SSO. Self-contained
 * so the file runs in isolation as well as in the full suite.
 */
function ssoAdmin(string $slug, bool $entitled = true): string
{
    $subject = app(Subjects::class)->create("admin@{$slug}.test", 'Admin', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', $slug));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    // AND THE SESSION KEY THE GUARD READS ON THE WAY IN. Every action below is a request
    // now, and without this each one answers a redirect to /login — which an assertion
    // about a write NOT happening passes.
    session([PlatformAuth::SESSION_KEY => $session->id]);

    if ($entitled) {
        app(EntitlementWriter::class)->set(
            $org->id,
            new EntitlementInput('cbox-id-sso', ['enabled' => true]),
            EntitlementSource::Manual,
        );
    }

    return $org->id;
}

/** Add a domain the way the form does. */
function addDomain(string $domain): TestResponse
{
    return test()->from(route('connections'))
        ->post(route('connections.domains.store'), ['domain' => $domain]);
}

/** Press Verify or the capture toggle on one domain row. */
function domainAction(string $id, string $action): TestResponse
{
    return test()->from(route('connections'))
        ->post(route('connections.domains.'.$action, $id));
}

it('lets an entitled admin add a domain and reveals its DNS challenge', function () {
    $orgId = ssoAdmin('dom-add');

    // Upper-case → normalized to lowercase.
    addDomain('ACME.com')->assertSessionHasNoErrors();

    $record = VerifiedDomain::query()->where('organization_id', $orgId)->where('domain', 'acme.com')->first();

    // The challenge rides back on the FLASH CHANNEL: it is the answer to one action, shown
    // once so the administrator can publish the TXT record, and re-issued rather than
    // re-read if it is lost.
    $flash = session()->get(SessionKey::FLASH_DATA, []);
    $dns = is_array($flash) ? ($flash['dns'] ?? null) : null;

    expect($record)->not->toBeNull()
        ->and($dns['token'] ?? null)->toBe($record->verification_token)
        ->and($dns['host'] ?? null)->toBe('_cbox-id-challenge.acme.com')
        ->and($dns['domain'] ?? null)->toBe('acme.com');
});

it('rejects a malformed domain', function () {
    $orgId = ssoAdmin('dom-bad');

    addDomain('not a domain')->assertSessionHasErrors('domain');
});

it('surfaces a friendly error when the domain is already claimed by another org', function () {
    $orgA = ssoAdmin('dom-claim-a');
    app(DomainVerification::class)->add($orgA, 'acme.com'); // org A claims it first

    ssoAdmin('dom-claim-b'); // now acting as a different org's admin

    addDomain('acme.com')->assertSessionHasErrors('domain');
});

it('refuses every domain action for a non-entitled org', function () {
    ssoAdmin('dom-deny', entitled: false);

    addDomain('acme.com')->assertForbidden();
    domainAction('vd_x', 'verify')->assertForbidden();
    domainAction('vd_x', 'capture')->assertForbidden();
    test()->from(route('connections'))->delete(route('connections.domains.destroy', 'vd_x'))->assertForbidden();
});

it('verifies a domain when the TXT record is present', function () {
    $orgId = ssoAdmin('dom-verify-ok');

    // Bind the in-memory DNS fake (no network) and publish the expected challenge.
    $dns = $this->fakeDns();
    $domains = app(DomainVerification::class);
    $record = $domains->add($orgId, 'acme.com');
    $dns->publish($domains->challengeHost('acme.com'), $record->verification_token);

    domainAction($record->id, 'verify')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Domain verified.');

    expect($record->refresh()->isVerified())->toBeTrue();
});

it('flashes a not-found message when the TXT record is missing', function () {
    $orgId = ssoAdmin('dom-verify-fail');

    // Fake DNS bound, but publish nothing — verify() must fail closed.
    $this->fakeDns();
    $record = app(DomainVerification::class)->add($orgId, 'acme.com');

    domainAction($record->id, 'verify')
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'DNS can take a few minutes'));

    expect($record->refresh()->isVerified())->toBeFalse();
});

it('toggles capture only on a verified domain', function () {
    $orgId = ssoAdmin('dom-capture');

    $verified = $this->makeVerifiedDomain($orgId, 'acme.com');
    $pending = app(DomainVerification::class)->add($orgId, 'pending.com');

    // Verified → capture flips on.
    domainAction($verified->id, 'capture')->assertSessionHasNoErrors();
    expect($verified->refresh()->capture)->toBeTrue();

    // Pending → refused (deny-by-default: capture requires proven ownership).
    domainAction($pending->id, 'capture')->assertForbidden();
    expect($pending->refresh()->capture)->toBeFalse();
});

it('refuses acting on another org\'s domain id (cross-org tampering)', function () {
    ssoAdmin('dom-a');

    // A domain owned by a DIFFERENT org; admin A stays the current user.
    $orgB = app(Organizations::class)->create(new NewOrganization('B', 'dom-b'));
    $foreign = app(DomainVerification::class)->add($orgB->id, 'foreign.com');

    domainAction($foreign->id, 'verify')->assertForbidden();
    domainAction($foreign->id, 'capture')->assertForbidden();
    test()->from(route('connections'))->delete(route('connections.domains.destroy', $foreign->id))->assertForbidden();

    // Untouched.
    expect(VerifiedDomain::query()->whereKey($foreign->id)->exists())->toBeTrue();
});
