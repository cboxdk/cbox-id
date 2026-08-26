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

it('answers a redirect to a page Volt still serves with a location response', function (): void {
    // THE CASE THIS MIDDLEWARE EXISTS FOR, asked of a page that is still Volt.
    //
    // It used to be asked of the sign-in, because the dashboard was one — and the dashboard
    // is React now, so that case moved to the test below. Repointing rather than deleting:
    // the hazard is unchanged while ANY page is still served by a closure, and this goes
    // when the last of them does, along with the middleware's Volt half.
    $target = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($route): bool => $route->getActionName() === 'Closure'
            && in_array('GET', $route->methods(), true)
            && ! str_contains((string) $route->uri(), '{'));

    expect($target)->not->toBeNull('no Volt page is left — delete this case and the middleware half it covers');

    $response = $this->withHeader('X-Inertia', 'true')
        ->post(route('password.email'), ['email' => 'nobody@acme.test'], ['Referer' => url($target->uri())]);

    // 409 + the header, NOT a 302: the client turns this into a real navigation, which is
    // the only thing that can render a page it does not own.
    $response->assertStatus(409)->assertHeader('X-Inertia-Location', url($target->uri()));
})->group('security');

/**
 * AND A SIGN-IN IS AN ORDINARY REDIRECT AGAIN, because the dashboard it lands on is a page
 * this client owns.
 *
 * This is the case that used to need the location response, and the reason the middleware
 * decides from the DESTINATION rather than from the controller: nobody edited the sign-in
 * when the dashboard ported, and it went back to being a plain 302 by itself.
 */
it('leaves a sign-in as a plain redirect now that the dashboard is a React page', function (): void {
    $subject = app(Subjects::class)->create('redirect@acme.test', 'Ada', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-redirect'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $this->withHeader('X-Inertia', 'true')
        ->post(route('login.attempt'), [
            'email' => 'redirect@acme.test',
            'password' => 'a-strong-unbreached-passphrase',
        ])
        ->assertStatus(302)
        ->assertRedirect(route('dashboard'));

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
