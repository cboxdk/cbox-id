<?php

declare(strict_types=1);

use Cbox\Id\Federation\Contracts\DnsResolver;
use Cbox\Id\Federation\Testing\FakeDnsResolver;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Contracts\EnvironmentDomains;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Livewire\Volt\Volt;

// Guarded so they coexist with the same helpers in the other workspace test files.
beforeEach(function (): void {
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    // Swap in an in-memory DNS resolver, then forget the domain service so it
    // rebuilds against the fake.
    $this->dns = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $this->dns);
    app()->forgetInstance(EnvironmentDomains::class);
});

it('walks an admin from requesting a custom domain to a verified issuer', function (): void {
    ['member' => $owner, 'organization' => $account, 'environment' => $env] = provisionAccount();
    signInAsMember($owner->user_id);

    $page = Volt::test('console.environment-domains')
        ->set('selectedEnvironment', $env->id)
        ->set('newDomain', 'id.acme.com')
        ->call('request');

    // The exact TXT record to publish is now shown.
    $challenge = app(EnvironmentDomains::class)->challenge($env->id);
    expect($challenge)->not->toBeNull()
        ->and($challenge->recordName)->toBe('_cbox-id-challenge.id.acme.com');
    $page->assertSee($challenge->recordName)->assertSee($challenge->recordValue);

    // Not verified until the record is live.
    $page->call('verify');
    expect($env->fresh()->domain)->toBeNull();
    $page->assertSee('visible yet');

    // Publish the record and verify: the domain is promoted + the event is logged.
    $this->dns->publish($challenge->recordName, $challenge->recordValue);
    $page->call('verify');

    expect($env->fresh()->domain)->toBe('id.acme.com')
        ->and(AuditEntry::query()->where('scope', $account->id)
            ->where('action', 'organization.custom_domain_verified')->exists())->toBeTrue();
});

it('surfaces a validation error for a platform-reserved domain', function (): void {
    ['member' => $owner, 'environment' => $env] = provisionAccount();
    signInAsMember($owner->user_id);

    Volt::test('console.environment-domains')
        ->set('selectedEnvironment', $env->id)
        ->set('newDomain', 'acme.cboxid.com')
        ->call('request')
        ->assertHasErrors('newDomain');

    expect(app(EnvironmentDomains::class)->challenge($env->id))->toBeNull();
});

it('removes a verified domain, falling back to the default issuer', function (): void {
    ['member' => $owner, 'environment' => $env] = provisionAccount();
    $env->update(['domain' => 'id.acme.com']);
    signInAsMember($owner->user_id);

    Volt::test('console.environment-domains')
        ->set('selectedEnvironment', $env->id)
        ->call('remove');

    expect($env->fresh()->domain)->toBeNull();
});

it('refuses the domains page to a member who cannot manage environments', function (): void {
    ['organization' => $account] = provisionAccount();
    $viewer = memberWithRole($account->id, MembershipRole::Viewer, 'billing2@acme.example');

    signInAsMember($viewer->user_id);
    $this->get(route('environment-domains'))
        ->assertRedirect(route('projects'));
});

/**
 * A member must not be able to read another account's domain challenge.
 *
 * `selectedEnvironment` is live-bound and unlocked. Every WRITE on this page funnels
 * through guard(), and the verified-domain read is constrained by the accessible id
 * list — but the challenge read passed the raw property to a service that resolves it
 * with a bare `Environment::find()`. `Environment` is the tenancy root and carries no
 * scope of its own, so the value came back: another account's unannounced domain, and
 * the `cbox-id-domain-verification=…` TXT record that proves ownership of it.
 */
it('does not leak another account domain challenge through the selected environment', function (): void {
    $mine = provisionAccount('mine@acme.example');
    $theirs = provisionAccount('theirs@other.example');

    // Their environment has a pending domain, so a challenge exists to leak.
    app(EnvironmentDomains::class)->request($theirs['environment']->id, 'id.other.example');

    signInAsMember($mine['member']);

    $component = Volt::test('console.environment-domains')
        ->set('selectedEnvironment', $theirs['environment']->id);

    expect($component->viewData('challenge'))
        ->toBeNull('a member read another account domain-verification challenge');

    // Positive control: their own environment still resolves one.
    app(EnvironmentDomains::class)->request($mine['environment']->id, 'id.acme.example');

    expect(Volt::test('console.environment-domains')
        ->set('selectedEnvironment', $mine['environment']->id)
        ->viewData('challenge'))
        ->not->toBeNull('the page stopped showing a member their own challenge');
});
