<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
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
use Livewire\Volt\Volt;

uses(InteractsWithFederation::class);

/**
 * Sign an admin (owner) into a fresh org, optionally entitled to SSO. Self-contained
 * so the file runs in isolation as well as in the full suite.
 */
function ssoAdmin(string $slug, bool $entitled = true): string
{
    $subject = app(Subjects::class)->create("admin@{$slug}.test", 'Admin', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', $slug));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    if ($entitled) {
        app(EntitlementWriter::class)->set(
            $org->id,
            new EntitlementInput('cbox-id-sso', ['enabled' => true]),
            EntitlementSource::Manual,
        );
    }

    return $org->id;
}

it('lets an entitled admin add a domain and reveals its DNS challenge', function () {
    $orgId = ssoAdmin('dom-add');

    $component = Volt::test('connections')
        ->set('domain', 'ACME.com') // upper-case → normalized to lowercase
        ->call('addDomain')
        ->assertHasNoErrors();

    $record = VerifiedDomain::query()->where('organization_id', $orgId)->where('domain', 'acme.com')->first();

    expect($record)->not->toBeNull()
        ->and($component->get('dnsToken'))->toBe($record->verification_token)
        ->and($component->get('dnsHost'))->toBe('_cbox-id-challenge.acme.com')
        ->and($component->get('dnsDomain'))->toBe('acme.com');
});

it('rejects a malformed domain', function () {
    $orgId = ssoAdmin('dom-bad');

    Volt::test('connections')
        ->set('domain', 'not a domain')
        ->call('addDomain')
        ->assertHasErrors('domain');
});

it('surfaces a friendly error when the domain is already claimed by another org', function () {
    $orgA = ssoAdmin('dom-claim-a');
    app(DomainVerification::class)->add($orgA, 'acme.com'); // org A claims it first

    ssoAdmin('dom-claim-b'); // now acting as a different org's admin

    Volt::test('connections')
        ->set('domain', 'acme.com')
        ->call('addDomain')
        ->assertHasErrors('domain');
});

it('refuses every domain action for a non-entitled org', function () {
    ssoAdmin('dom-deny', entitled: false);

    Volt::test('connections')->set('domain', 'acme.com')->call('addDomain')->assertForbidden();
    Volt::test('connections')->call('verifyDomain', 'vd_x')->assertForbidden();
    Volt::test('connections')->call('toggleCapture', 'vd_x')->assertForbidden();
    Volt::test('connections')->call('removeDomain', 'vd_x')->assertForbidden();
});

it('verifies a domain when the TXT record is present', function () {
    $orgId = ssoAdmin('dom-verify-ok');

    // Bind the in-memory DNS fake (no network) and publish the expected challenge.
    $dns = $this->fakeDns();
    $domains = app(DomainVerification::class);
    $record = $domains->add($orgId, 'acme.com');
    $dns->publish($domains->challengeHost('acme.com'), $record->verification_token);

    Volt::test('connections')
        ->call('verifyDomain', $record->id)
        ->assertHasNoErrors()
        // The confirmation is dispatched to the layout's toast now, not rendered into
        // the component — Livewire never re-rendered the layout on an action, so a
        // flash from a non-redirecting action displayed nothing at all.
        ->assertDispatched('toast', message: 'Domain verified.');

    expect($record->refresh()->isVerified())->toBeTrue();
});

it('flashes a not-found message when the TXT record is missing', function () {
    $orgId = ssoAdmin('dom-verify-fail');

    // Fake DNS bound, but publish nothing — verify() must fail closed.
    $this->fakeDns();
    $record = app(DomainVerification::class)->add($orgId, 'acme.com');

    Volt::test('connections')
        ->call('verifyDomain', $record->id)
        ->assertDispatched('toast', fn (string $event, array $params): bool => str_contains(
            (string) ($params['message'] ?? ''),
            'DNS can take a few minutes',
        ));

    expect($record->refresh()->isVerified())->toBeFalse();
});

it('toggles capture only on a verified domain', function () {
    $orgId = ssoAdmin('dom-capture');

    $verified = $this->makeVerifiedDomain($orgId, 'acme.com');
    $pending = app(DomainVerification::class)->add($orgId, 'pending.com');

    // Verified → capture flips on.
    Volt::test('connections')->call('toggleCapture', $verified->id)->assertHasNoErrors();
    expect($verified->refresh()->capture)->toBeTrue();

    // Pending → refused (deny-by-default: capture requires proven ownership).
    Volt::test('connections')->call('toggleCapture', $pending->id)->assertForbidden();
    expect($pending->refresh()->capture)->toBeFalse();
});

it('refuses acting on another org\'s domain id (cross-org tampering)', function () {
    ssoAdmin('dom-a');

    // A domain owned by a DIFFERENT org; admin A stays the current user.
    $orgB = app(Organizations::class)->create(new NewOrganization('B', 'dom-b'));
    $foreign = app(DomainVerification::class)->add($orgB->id, 'foreign.com');

    Volt::test('connections')->call('verifyDomain', $foreign->id)->assertForbidden();
    Volt::test('connections')->call('toggleCapture', $foreign->id)->assertForbidden();
    Volt::test('connections')->call('removeDomain', $foreign->id)->assertForbidden();

    // Untouched.
    expect(VerifiedDomain::query()->whereKey($foreign->id)->exists())->toBeTrue();
});
