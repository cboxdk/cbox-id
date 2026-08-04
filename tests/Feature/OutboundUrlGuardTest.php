<?php

declare(strict_types=1);

use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Exceptions\UnsafeWebhookUrl;
use Cbox\Ssrf\Contracts\Resolver;
use Tests\Support\FixedDns;

/**
 * Proof that the SSRF guard is LIVE in this suite, and not merely quiet.
 *
 * {@see FixedDns} exists because every host the suite invents is fictional and the guard
 * resolves a hostname before it will store an outbound URL. Answering DNS from a fixture
 * makes hundreds of tests stop depending on the resolver of the machine they run on — and
 * it would also, if nothing checked, be an excellent way to disable the guard by
 * accident. That is exactly what the `.env` line it replaced was doing: with
 * `CBOX_ID_WEBHOOKS_VERIFY_URL=false` the local suite reported everything green while the
 * guard never ran once.
 *
 * So these do not test `FixedDns`. They map a host to an address the guard must object to
 * and assert it objects — which fails if the flag is turned off again, if the binding is
 * dropped, or if a future refactor stops consulting the resolver at registration.
 */
beforeEach(function (): void {
    $this->organization = app(Organizations::class)->create(new NewOrganization('Guarded', 'guarded'));
});

it('refuses a host that resolves to the cloud metadata service', function (): void {
    // The canonical SSRF target: a name under the attacker's control, pointed at the
    // link-local address that hands out instance credentials. Nothing about the URL looks
    // wrong — the refusal can only come from resolving it.
    app(Resolver::class)->set('rebind.example', ['169.254.169.254']);

    expect(fn () => app(WebhookRegistry::class)->register(
        $this->organization->id,
        'https://rebind.example/events',
        ['user.created'],
    ))->toThrow(UnsafeWebhookUrl::class);
})->group('security');

it('refuses a host that resolves into a private network', function (): void {
    app(Resolver::class)->set('internal.example', ['10.0.0.7']);

    expect(fn () => app(WebhookRegistry::class)->register(
        $this->organization->id,
        'https://internal.example/events',
        ['user.created'],
    ))->toThrow(UnsafeWebhookUrl::class);
})->group('security');

it('refuses a host that does not resolve at all', function (): void {
    // The state every fictional host in this suite is really in. It is stated rather than
    // inherited, so the assertion holds on a machine whose resolver answers wildcards for
    // anything — which is how this class of test passes for the wrong reason.
    app(Resolver::class)->set('gone.example', []);

    expect(fn () => app(WebhookRegistry::class)->register(
        $this->organization->id,
        'https://gone.example/events',
        ['user.created'],
    ))->toThrow(UnsafeWebhookUrl::class);
})->group('security');

it('accepts a host that resolves to public unicast', function (): void {
    // The other half: a guard that refused everything would satisfy the three above and
    // be useless. This is the ordinary answer FixedDns gives any host it was not told
    // about, and it is why the rest of the suite can name hosts freely.
    $result = app(WebhookRegistry::class)->register(
        $this->organization->id,
        'https://hooks.customer.example/events',
        ['user.created'],
    );

    expect($result->endpoint->url)->toBe('https://hooks.customer.example/events');
});
