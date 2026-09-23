<?php

declare(strict_types=1);

use App\Models\AdminPortalLink;
use App\Platform\AdminPortal;
use App\Platform\CurrentUser;
use App\Platform\Enums\PortalScope;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\EmailVerification;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Inertia\Testing\AssertableInertia;

/**
 * OPENING A MAILED LINK SPENDS NOTHING.
 *
 * Every single-use link this product mails — an organization invitation, a sign-in link, an
 * address confirmation, an Admin Portal setup link — was redeemed by its GET. That is the one
 * request a person never makes alone: Outlook Safe Links, Mimecast, Proofpoint and the link
 * previews in Slack and Teams fetch a URL before a human sees it. The fetch spent the token,
 * and for the invitation and the sign-in link it was the SCANNER that got the session.
 *
 * Each door is therefore asked the same two things: a GET (made twice, as scanners do)
 * leaves the token exactly as live as it was, and the POST the page's button makes is what
 * spends it.
 */
beforeEach(function (): void {
    installedDeployment();
});

it('shows an organization invitation without accepting it, then accepts it on the POST', function (): void {
    [$inviterId, $org] = actingAsRole(MembershipRole::Owner);
    $pending = app(Invitations::class)->invite($org->id, 'joiner@acme.test', MembershipRole::Member, invitedBy: $inviterId);

    // A scanner, then the person — both GETs.
    app(PlatformAuth::class)->logout(request());
    $this->get(route('invitation.accept', $pending->token))->assertOk();

    $this->get(route('invitation.accept', $pending->token))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/join-organization')
            ->where('confirmation.heading', 'Join Acme?')
            ->where('confirmation.actionUrl', route('invitation.accept.store', $pending->token))
            // Who is inviting whom to what — the facts that let somebody spot an
            // invitation they were not expecting.
            ->where('confirmation.facts.0', ['label' => 'Organization', 'value' => 'Acme'])
            ->where('confirmation.facts.1', ['label' => 'Invited by', 'value' => 'Owner'])
            ->where('confirmation.facts.2', ['label' => 'Role', 'value' => 'Member']));

    // Nothing was spent: still pending, nobody created, nobody signed in.
    expect(app(Invitations::class)->byToken($pending->token)?->isPending())->toBeTrue()
        ->and(app(Subjects::class)->findByEmail('joiner@acme.test'))->toBeNull()
        ->and(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();

    $this->post(route('invitation.accept.store', $pending->token))->assertRedirect(route('dashboard'));

    $joiner = app(Subjects::class)->findByEmail('joiner@acme.test');

    expect($joiner)->not->toBeNull()
        ->and(app(Memberships::class)->of($org->id, (string) $joiner?->id)?->role)->toBe(MembershipRole::Member)
        ->and(session()->has(PlatformAuth::SESSION_KEY))->toBeTrue();

    // And the link is single-use: the page for a spent one sends people to sign in.
    $this->get(route('invitation.accept', $pending->token))->assertRedirect(route('login'));
    $this->post(route('invitation.accept.store', $pending->token))->assertRedirect(route('login'));
});

it('shows a sign-in link without redeeming it, then signs in on the POST', function (): void {
    User::query()->create(['email' => 'magic@acme.test', 'name' => 'Magic', 'status' => 'active']);
    $token = app(MagicLink::class)->request('magic@acme.test');

    $this->get(route('magic.redeem', $token))->assertOk();
    $this->get(route('magic.redeem', $token))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/confirm-sign-in')
            ->where('confirmation.actionUrl', route('magic.redeem.store', $token)));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();

    // The token survived both GETs — which is the whole claim.
    $this->post(route('magic.redeem.store', $token))->assertRedirect(route('dashboard'));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeTrue();
});

it('shows an address-confirmation link without confirming it, then confirms on the POST', function (): void {
    $subject = app(Subjects::class)->create('unverified@acme.test', 'Unverified', 'a-strong-unbreached-passphrase');
    $token = app(EmailVerification::class)->issue($subject->id, 'unverified@acme.test');

    $this->get(route('verification.verify', $token))->assertOk();
    $this->get(route('verification.verify', $token))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/confirm-email')
            ->where('confirmation.actionUrl', route('verification.verify.store', $token)));

    expect(app(Subjects::class)->find($subject->id)?->emailVerified)->toBeFalse();

    $this->post(route('verification.verify.store', $token))->assertRedirect(route('login'));

    expect(app(Subjects::class)->find($subject->id)?->emailVerified)->toBeTrue();
});

it('shows an Admin Portal setup link without entering it, then enters on the POST', function (): void {
    config(['cbox-id.entitlements.mode' => 'metered']);
    $orgId = gateAdmin('portal-single-use');
    grantFeature($orgId, 'cbox-id-sso');
    $token = app(AdminPortal::class)->generate($orgId, PortalScope::Sso, 'sub_creator');

    // A chat unfurler, then the IT admin.
    $this->get(route('portal.enter', $token))->assertOk();
    $this->get(route('portal.enter', $token))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/open-portal-setup')
            ->where('confirmation.actionUrl', route('portal.enter.store', $token)));

    expect(AdminPortalLink::query()->where('organization_id', $orgId)->value('consumed_at'))->toBeNull()
        ->and(session()->has(AdminPortal::SESSION_KEY))->toBeFalse();

    $this->post(route('portal.enter.store', $token))->assertRedirect(route('portal.setup'));

    expect(AdminPortalLink::query()->where('organization_id', $orgId)->value('consumed_at'))->not->toBeNull()
        ->and(session()->has(AdminPortal::SESSION_KEY))->toBeTrue();
});

it('answers a spent or unknown invitation on the page, before anyone presses anything', function (): void {
    $this->get(route('invitation.accept', 'inv_does_not_exist'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'That invitation is invalid or has expired.');

    expect(app(CurrentUser::class)->check())->toBeFalse();
});
