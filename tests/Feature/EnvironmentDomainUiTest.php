<?php

declare(strict_types=1);

use Cbox\Id\Federation\Contracts\DnsResolver;
use Cbox\Id\Federation\Testing\FakeDnsResolver;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Contracts\EnvironmentDomains;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\PlatformRoot;
use Inertia\Testing\AssertableInertia;

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
    ['member' => $owner, 'subjectId' => $ownerSubjectId, 'organization' => $account, 'environment' => $env] = provisionAccount();
    signInAsMember($ownerSubjectId);

    $on = fn (): string => route('environment-domains', ['environment' => $env->id]);

    test()->from($on())
        ->post(route('environment-domains.store'), [
            'environment' => $env->id,
            'domain' => 'id.acme.com',
        ])
        ->assertSessionHasNoErrors();

    $challenge = app(EnvironmentDomains::class)->challenge($env->id);
    expect($challenge)->not->toBeNull()
        ->and($challenge->recordName)->toBe('_cbox-id-challenge.id.acme.com');

    // THE EXACT RECORD TO PUBLISH, on the page. Somebody is about to copy three values
    // into a DNS panel in another tab, so this is the whole content of this state.
    test()->get($on())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('challenge.recordName', $challenge->recordName)
            ->where('challenge.recordValue', $challenge->recordValue));

    // Not verified until the record is live — and the refusal says what to DO rather than
    // implying the domain needs correcting.
    test()->from($on())
        ->post(route('environment-domains.verify'), ['environment' => $env->id])
        ->assertSessionHasErrors(['verify' => 'The DNS TXT record isn\'t visible yet. DNS can take a few minutes to propagate — try again shortly.']);

    expect($env->fresh()->domain)->toBeNull();

    // Publish the record and verify: the domain is promoted and the event is logged.
    $this->dns->publish($challenge->recordName, $challenge->recordValue);

    test()->from($on())
        ->post(route('environment-domains.verify'), ['environment' => $env->id])
        ->assertSessionHasNoErrors();

    expect($env->fresh()->domain)->toBe('id.acme.com')
        ->and(app(PlatformRoot::class)->run(fn (): bool => AuditEntry::query()->where('scope', $account->id)
            ->where('action', 'organization.custom_domain_verified')->exists()))->toBeTrue();
});

it('surfaces a validation error for a platform-reserved domain', function (): void {
    ['member' => $owner, 'subjectId' => $ownerSubjectId, 'environment' => $env] = provisionAccount();
    signInAsMember($ownerSubjectId);

    test()->from(route('environment-domains', ['environment' => $env->id]))
        ->post(route('environment-domains.store'), [
            'environment' => $env->id,
            'domain' => 'acme.cboxid.com',
        ])
        ->assertSessionHasErrors('domain');

    expect(app(EnvironmentDomains::class)->challenge($env->id))->toBeNull();
});

it('removes a verified domain, falling back to the default issuer', function (): void {
    ['member' => $owner, 'subjectId' => $ownerSubjectId, 'environment' => $env] = provisionAccount();
    $env->update(['domain' => 'id.acme.com']);
    signInAsMember($ownerSubjectId);

    test()->from(route('environment-domains', ['environment' => $env->id]))
        ->delete(route('environment-domains.destroy'), ['environment' => $env->id])
        ->assertSessionHasNoErrors();

    expect($env->fresh()->domain)->toBeNull();
});

it('refuses the domains page to a member who cannot manage environments', function (): void {
    ['organization' => $account] = provisionAccount();
    [$viewer, $viewerSubjectId] = memberWithRole($account->id, MembershipRole::Viewer, 'billing2@acme.example');

    signInAsMember($viewerSubjectId);
    $this->get(route('environment-domains'))
        ->assertRedirect(route('projects'));
});

/**
 * A member must not be able to read another account's domain challenge.
 *
 * The environment id is whatever the browser says — a live-bound Livewire property once, a
 * query parameter now — and the bug was that the READ did not go through the same
 * resolution as the writes. Every write funnelled through a reachability guard and the
 * verified-domain read was constrained by the accessible id list, but the CHALLENGE read
 * passed the raw value to a service that resolves it with a bare `Environment::find()`.
 * `Environment` is the tenancy root and carries no scope of its own, so the value came
 * back: another account's unannounced domain, and the `cbox-id-domain-verification=…` TXT
 * record that proves ownership of it. A bogus id 500'd rather than 404'd, which was its
 * own tell.
 */
it('does not leak another account domain challenge through the selected environment', function (): void {
    $mine = provisionAccount('mine@acme.example');
    $theirs = provisionAccount('theirs@other.example');

    // Their environment has a pending domain, so a challenge exists to leak.
    app(EnvironmentDomains::class)->request($theirs['environment']->id, 'id.other.example');

    signInAsMember($mine['subjectId']);

    /*
     * NAMED IN THE URL, which is exactly the shape that used to leak: the id was a live-
     * bound property then and it is a query parameter now, and either way it is whatever
     * the browser says. What must hold is that an id outside the reachable set never
     * reaches a service that resolves it unscoped.
     */
    test()->get(route('environment-domains', ['environment' => $theirs['environment']->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('challenge', null)
            // …and the page fell back to one that IS theirs rather than rendering a blank
            // form addressed at somebody else's environment.
            ->where('selected', $mine['environment']->id));

    // And no write reaches it either.
    test()->post(route('environment-domains.verify'), ['environment' => $theirs['environment']->id])
        ->assertForbidden();
    test()->delete(route('environment-domains.destroy'), ['environment' => $theirs['environment']->id])
        ->assertForbidden();

    expect(app(EnvironmentDomains::class)->challenge($theirs['environment']->id))
        ->not->toBeNull('a foreign write cleared another account\'s pending domain');

    // Positive control: their own environment still resolves one.
    app(EnvironmentDomains::class)->request($mine['environment']->id, 'id.acme.example');

    test()->get(route('environment-domains', ['environment' => $mine['environment']->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('challenge.domain', 'id.acme.example'));
});
