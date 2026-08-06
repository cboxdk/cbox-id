<?php

declare(strict_types=1);

use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * Capture routes every person on an email domain to that organization's SSO connection.
 * Turning it on for a domain nobody proved they own lets an organization claim addresses
 * belonging to someone else — which is why the subject console has always refused it.
 *
 * The environment-admin door did not, and neither does the framework's
 * `DomainVerification::setCapture()`, so the weaker of the two callers decided the rule.
 * The durable fix belongs in the framework service; this pins the app's half.
 */
it('refuses to enable capture on a domain that is not verified', function (): void {
    ['envId' => $envId] = crudSetup();

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-capture'));
    $domain = app(DomainVerification::class)->add($org->id, 'unproven.test');

    expect($domain->isVerified())->toBeFalse();

    Volt::test('environment.organizations.show', ['organization' => $org->id])
        ->call('toggleCapture', $domain->id);

    expect(VerifiedDomain::query()->whereKey($domain->id)->value('capture'))->toBeFalsy();
});

it('allows capture once the domain is verified, and allows turning it back off', function (): void {
    ['envId' => $envId] = crudSetup();

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-capture-ok'));
    $domain = app(DomainVerification::class)->add($org->id, 'proven.test');
    VerifiedDomain::query()->whereKey($domain->id)->update(['verified_at' => now()]);

    $page = Volt::test('environment.organizations.show', ['organization' => $org->id]);

    $page->call('toggleCapture', $domain->id);
    expect(VerifiedDomain::query()->whereKey($domain->id)->value('capture'))->toBeTruthy();

    // Turning it OFF must never be blocked — removing a claim can only be safe.
    $page->call('toggleCapture', $domain->id);
    expect(VerifiedDomain::query()->whereKey($domain->id)->value('capture'))->toBeFalsy();
});
