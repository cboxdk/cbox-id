<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PendingLink;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Organization\Enums\MembershipRole;
use Inertia\Testing\AssertableInertia;
use Livewire\Volt\Volt;

/**
 * Sign in through the real login component, then populate CurrentUser the way the
 * Authenticate middleware does on a real request — Volt::test does not run middleware,
 * so a component that asks "who am I" would otherwise be told "nobody".
 */
function signInAndResolve(string $email, string $password = 'supersecret123'): void
{
    Volt::test('auth.login')->set('email', $email)->set('password', $password)->call('login');

    $sessionId = session()->get(PlatformAuth::SESSION_KEY);
    $session = app(SessionManager::class)->active(is_string($sessionId) ? $sessionId : '');
    $subject = app(Subjects::class)->find((string) $session?->user_id);

    if ($subject !== null && $session !== null) {
        app(CurrentUser::class)->set($subject, $session, null, null);
    }
}

it('does not link a held identity until the user says so', function () {
    $subject = app(Subjects::class)->create('dana@acme.test', 'Dana', 'supersecret123');

    // A social sign-in for the same email was held aside (email already taken).
    app(PlatformAuth::class)->startPendingLink(new FederatedPrincipal('social:google', 'g|1', 'dana@acme.test', 'Dana'));

    // Signing in proves control of THIS account — and that is all it proves. It used to
    // complete the link on its own whenever the addresses matched, which trusted the
    // provider's word for an address we had already decided not to trust.
    Volt::test('auth.login')
        ->set('email', 'dana@acme.test')
        ->set('password', 'supersecret123')
        ->call('login')
        ->assertRedirect(route('dashboard'));

    expect(app(Subjects::class)->linkedIdentities($subject->id))->toBeEmpty();

    // The identity is held, bound to this account, waiting to be asked about.
    expect(app(PlatformAuth::class)->pendingLink($subject->id))->not->toBeNull();
});

it('links the held identity once the signed-in user confirms', function () {
    $subject = app(Subjects::class)->create('dana@acme.test', 'Dana', 'supersecret123');
    app(PlatformAuth::class)->startPendingLink(new FederatedPrincipal('social:google', 'g|1', 'dana@acme.test', 'Dana'));

    signInAndResolve('dana@acme.test');

    Volt::test('auth.link-confirm')->call('connect');

    expect(collect(app(Subjects::class)->linkedIdentities($subject->id))
        ->contains(fn ($identity): bool => $identity->provider === 'social:google' && $identity->subject === 'g|1'))->toBeTrue();
});

it('links nothing when the user declines', function () {
    $subject = app(Subjects::class)->create('dana@acme.test', 'Dana', 'supersecret123');
    app(PlatformAuth::class)->startPendingLink(new FederatedPrincipal('social:google', 'g|1', 'dana@acme.test', 'Dana'));

    signInAndResolve('dana@acme.test');

    // "No, that wasn't me" — the answer someone gives when another person tried to sign
    // in using their address. It must leave the account exactly as it was, and it must
    // not leave the identity sitting there to be asked about again.
    Volt::test('auth.link-confirm')->call('decline');

    expect(app(Subjects::class)->linkedIdentities($subject->id))->toBeEmpty()
        ->and(app(PlatformAuth::class)->pendingLink($subject->id))->toBeNull();
});

it('accepts an identity whose email differs from the account, because the user said so', function () {
    // The old rule required the held identity's email to equal the account's. People
    // have several addresses at one provider — a GitHub account may carry five — so the
    // legitimate owner's link was silently discarded and the feature simply appeared not
    // to work. Confirmation replaced equality: it proves more, and it does not care
    // which of someone's addresses the provider happened to send.
    $subject = app(Subjects::class)->create('dana@acme.test', 'Dana', 'supersecret123');
    app(PlatformAuth::class)->startPendingLink(new FederatedPrincipal('social:github', 'gh|9', 'dana@personal.test', 'Dana'));

    signInAndResolve('dana@acme.test');

    Volt::test('auth.link-confirm')->call('connect');

    expect(app(Subjects::class)->linkedIdentities($subject->id))->toHaveCount(1);
});

it('never lets a held identity be confirmed by a different account', function () {
    $victim = app(Subjects::class)->create('victim@acme.test', 'Victim', 'supersecret123');

    // Held for whoever owns attacker@evil.test. If signing in as anyone were enough to
    // claim it, an attacker could staple their provider account onto the next person to
    // log in on that browser — a permanent second way into someone else's account.
    app(PlatformAuth::class)->startPendingLink(new FederatedPrincipal('social:google', 'attacker|1', 'attacker@evil.test', 'Attacker'));

    signInAndResolve('victim@acme.test');

    // Nothing landed without an answer.
    expect(app(Subjects::class)->linkedIdentities($victim->id))->toBeEmpty();

    // And the held identity is bound to the account that authenticated — this app lets
    // someone hold several accounts at once and switch between them, so an identity
    // offered while looking at one account must not be claimable by another.
    $other = app(Subjects::class)->create('other@acme.test', 'Other', 'supersecret123');

    expect(app(PlatformAuth::class)->pendingLink($other->id))->toBeNull()
        ->and(app(PlatformAuth::class)->confirmPendingLink($other->id))->toBeFalse()
        ->and(app(Subjects::class)->linkedIdentities($other->id))->toBeEmpty();
});

it('stops offering a held identity once it has gone stale', function () {
    $subject = app(Subjects::class)->create('dana@acme.test', 'Dana', 'supersecret123');
    app(PlatformAuth::class)->startPendingLink(new FederatedPrincipal('social:google', 'g|1', 'dana@acme.test', 'Dana'));

    signInAndResolve('dana@acme.test');

    // An identity that sat in a session for a day is not evidence of anything, and a
    // link is a permanent new way into an account. Travel past the window.
    $this->travel(PendingLink::TTL_SECONDS + 1)->seconds();

    expect(app(PlatformAuth::class)->pendingLink($subject->id))->toBeNull();

    // And confirming is refused outright, not merely un-offered — the screen is not the
    // only way to reach confirmPendingLink(), so the window has to bind at the source.
    expect(app(PlatformAuth::class)->confirmPendingLink($subject->id))->toBeFalse()
        ->and(app(Subjects::class)->linkedIdentities($subject->id))->toBeEmpty();
});

it('shows connected accounts and lets a user disconnect one', function () {
    config(['services.google.client_id' => 'client', 'services.google.client_secret' => 'secret']);
    actingAsRole(MembershipRole::Owner);
    $id = app(CurrentUser::class)->id();

    // The user explicitly linked Google earlier.
    app(Subjects::class)->link($id, new FederatedPrincipal('social:google', 'google|1', 'owner@acme.test'));
    expect(app(Subjects::class)->linkedIdentities($id))->toHaveCount(1);

    // Disconnecting is sensitive → confirm step-up. The account keeps its password,
    // so the last-factor guard allows the unlink.
    app(Sudo::class)->confirm();
    Volt::test('account')
        ->assertSee('Connected accounts')
        ->call('unlinkProvider', 'google');

    expect(app(Subjects::class)->linkedIdentities($id))->toBeEmpty();
});

it('resolves a returning linked social identity back to the same account', function () {
    actingAsRole(MembershipRole::Owner);
    $id = app(CurrentUser::class)->id();
    $subjects = app(Subjects::class);

    $subjects->link($id, new FederatedPrincipal('social:github', 'gh|42', 'owner@acme.test'));

    // A later social sign-in with the linked identity returns the same subject.
    $resolved = $subjects->provisionFederated(new FederatedPrincipal('social:github', 'gh|42', 'owner@acme.test'));

    expect($resolved->id)->toBe($id);
});

it('holds every authenticated page until the question is answered', function () {
    app(Subjects::class)->create('dana@acme.test', 'Dana', 'supersecret123');
    app(PlatformAuth::class)->startPendingLink(new FederatedPrincipal('social:google', 'g|1', 'dana@acme.test', 'Dana'));

    Volt::test('auth.login')
        ->set('email', 'dana@acme.test')
        ->set('password', 'supersecret123')
        ->call('login');

    // Not a banner someone can scroll past. A half-made link is a question about who
    // may enter this account, and it gets answered before anything else happens.
    $this->get(route('account'))->assertRedirect(route('link.confirm'));

    // The screen itself must not be held, or the redirect loops and the account is
    // unreachable with no way to fix it — the failure mode the password hold already
    // taught us about.
    // Rendered through the real stack: the question, and the address it is about. A hold
    // that redirects to a screen nobody can act on is a lockout.
    //
    // Asserted on the page's PROPS rather than on its text. The words are the page's to
    // choose and it renders them in the browser, so scanning the response body for them
    // would be scanning a mount point — and would fail the day somebody improves the
    // copy, which is not a regression.
    $this->get(route('link.confirm'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('auth/link-confirm')
                ->where('provider', 'Google')
                ->where('email', 'dana@acme.test'),
        );
})->group('security');
