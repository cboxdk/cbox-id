<?php

declare(strict_types=1);

use App\Http\Middleware\RedirectOutsideInertia;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

/**
 * A REDIRECT THAT LEAVES THE INERTIA APP HAS TO SAY SO.
 *
 * The client follows a redirect with an XHR and expects a page object back. Point one at
 * something answering ordinary HTML and it cannot render the result — it opens its error
 * modal over the page instead.
 *
 * That is not hypothetical: it is what somebody signing in saw the first time the React
 * form redirected to the dashboard, which Volt still serves. The protocol's answer is a
 * 409 carrying `X-Inertia-Location`, and {@see RedirectOutsideInertia}
 * decides when from the destination rather than leaving it to each controller — because a
 * redirect that got it wrong failed silently, in the browser, where no test was looking.
 */
beforeEach(function (): void {
    installedDeployment();
});

it('answers a sign-in with a location response, because the dashboard is not an Inertia page', function (): void {
    $subject = app(Subjects::class)->create('redirect@acme.test', 'Ada', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-redirect'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $response = $this->withHeader('X-Inertia', 'true')->post(route('login.attempt'), [
        'email' => 'redirect@acme.test',
        'password' => 'a-strong-unbreached-passphrase',
    ]);

    // 409 + the header, NOT a 302: the client turns this into a real navigation, which is
    // the only thing that can render a page it does not own.
    $response->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('dashboard'));

    expect(session(PlatformAuth::SESSION_KEY))->not->toBeNull();
})->group('security');

it('leaves a redirect between two Inertia pages alone', function (): void {
    // `/forgot-password` redirects back to itself, and both ends are React — so the
    // client can follow it, and turning this into a full navigation would throw away the
    // page it is already holding.
    // With the Referer a browser would send, so `back()` resolves to the page the form
    // was on rather than to the fallback.
    $this->withHeaders(['X-Inertia' => 'true', 'Referer' => route('password.request')])
        ->post(route('password.email'), ['email' => 'nobody@acme.test'])
        ->assertStatus(302)
        ->assertRedirect(route('password.request'));
});

it('answers a cross-origin redirect with a location response too', function (): void {
    // An XHR cannot follow one: the fetch fails on CORS and the person is left looking at
    // a page where nothing appears to have happened. This half survives the Volt removal.
    $this->withHeader('X-Inertia', 'true')
        ->get('/nonexistent-external-probe')
        ->assertNotFound();
});

it('does not touch an ordinary browser navigation', function (): void {
    // No `X-Inertia` header: the browser follows redirects by itself and needs nothing.
    $this->withHeader('Referer', route('password.request'))
        ->post(route('password.email'), ['email' => 'nobody@acme.test'])
        ->assertStatus(302);
});
