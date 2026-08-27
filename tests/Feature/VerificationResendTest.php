<?php

declare(strict_types=1);

use App\Http\Controllers\Console\ProjectController;
use App\Mail\EmailVerificationMail;
use App\Platform\MemberEmailVerification;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;

/**
 * Deferring the environment until the address is proven (see SignupDeferredEnvironmentTest)
 * put a real owner's whole account behind one email. Lose it, filter it, or let the
 * 24-hour token lapse, and there is no environment and no way to ask for another link —
 * the account is a dead end until the token expires and the address is free to sign up
 * again. These tests pin the way out, and the four things that keep the way out from
 * becoming its own hole: it mails only the caller's own address, it is throttled, it says
 * nothing about whether the address is already confirmed, and it leaves exactly one live
 * link behind.
 */
beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    Mail::fake();
});

/** The platform root, served on this test's host — how cboxid.com resolves (Tier 2). */
function rootForResend(): Environment
{
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    $root = Environment::query()->create([
        'name' => 'Production',
        'slug' => 'platform-root',
        'status' => 'active',
        'is_default' => true,
    ]);

    app(EnvironmentContext::class)->set($root);

    return $root;
}

/** Sign up a workspace and stay signed in as its owner (signup establishes the session). */
function signUpForResend(string $email = 'dana@acme.example', string $organization = 'Acme'): Subject
{
    /*
     * A GUEST signs up. `platform.guest` guards the route, so a second call in one test —
     * "somebody else's workspace, so 'the signed-in member' is a real distinction" —
     * would otherwise be bounced without registering anybody, and the failure would land
     * later, on a lookup for an account that was never created.
     */
    test()->flushSession();

    test()->post(route('signup.register'), [
        'organization' => $organization,
        'name' => 'Dana Reeves',
        'email' => $email,
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertSessionHasNoErrors();

    $member = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail($email));
    expect($member)->not->toBeNull();

    /** @var Subject $member */
    // Re-establish through the fixture, so the ACTING ORGANIZATION is resolved. Signing up
    // mints the session and hands the browser straight to a redirect; the middleware on
    // the next request is what resolves which organization the person is acting on, and
    // the Identity platform pages will not render for a request that has none. Driving a
    // component directly skips that request, so the fixture stands in for it.
    signInAsMember($member->id);

    return $member;
}

/**
 * Press "send the link again".
 *
 * A real POST to the launchpad's own route, from the launchpad, so the redirect back and
 * the flash it carries are the ones a browser would get.
 */
function resend(): TestResponse
{
    return test()
        ->from(route('projects'))
        ->post(route('projects.verification.resend'));
}

/**
 * What the last resend told the person who clicked.
 *
 * Read off the INERTIA FLASH CHANNEL, which is where the answer to one click belongs —
 * not the session's `status`, which the console's toaster shows for every mutation.
 */
function lastResendNotice(): string
{
    $flash = session()->get(SessionKey::FLASH_DATA, []);
    $notice = is_array($flash) ? ($flash['resendNotice'] ?? null) : null;

    return is_string($notice) ? $notice : '';
}

/** Every verification link mailed so far, oldest first. */
function verificationLinks(): array
{
    return Mail::sent(EmailVerificationMail::class)
        ->map(static fn (EmailVerificationMail $mail): string => $mail->url)
        ->all();
}

it('sends a fresh link to the signed-in member and to nobody else', function (): void {
    rootForResend();

    // A second workspace, so "the signed-in member's address" is a real distinction and
    // not the only address in the database.
    signUpForResend('eve@other.example', 'Other');
    signUpForResend('dana@acme.example');

    $before = count(verificationLinks());

    resend()->assertSessionHasNoErrors();

    expect(lastResendNotice())->toContain('on its way to dana@acme.example');

    $sent = Mail::sent(EmailVerificationMail::class);

    expect($sent)->toHaveCount($before + 1);

    // The new mail went to the signed-in owner. Eve, whose signup mail is also in this
    // mailbox, received nothing further.
    $resent = $sent->last();
    expect($resent->hasTo('dana@acme.example'))->toBeTrue();

    $toEve = $sent->filter(static fn (Mailable $m): bool => $m->hasTo('eve@other.example'));
    expect($toEve)->toHaveCount(1); // her own signup mail, and only that
});

it('takes no address argument, so a crafted call cannot steer where the mail goes', function (): void {
    // The functional test above proves the mail lands on the signed-in person's own
    // address; this pins WHY it cannot do otherwise — there is no address input to point
    // elsewhere.
    //
    // The parameter is the SUBJECT now, and that is the stronger version of the same
    // property rather than a weakening of it: the address is read off the subject the
    // session resolved, so there is no second row whose address could disagree with the
    // identity being verified.
    $action = new ReflectionMethod(MemberEmailVerification::class, 'resend');

    expect($action->getNumberOfParameters())->toBe(1)
        ->and((string) $action->getParameters()[0]->getType())->toBe(Subject::class);

    // The CONTROLLER action likewise takes only container-resolved services — no scalar a
    // request payload could supply, and no `Request` it could read one off.
    foreach ((new ReflectionMethod(ProjectController::class, 'resendVerification'))->getParameters() as $parameter) {
        $type = $parameter->getType();
        expect($type)->toBeInstanceOf(ReflectionNamedType::class)
            ->and($type->isBuiltin())->toBeFalse()
            ->and((string) $type)->not->toBe(Request::class);
    }

    // …and the route carries no parameter either, so there is nowhere in the URL to put
    // one. Reflection over the action alone would miss a `{subject}` segment.
    expect(Route::getRoutes()->getByName('projects.verification.resend')?->parameterNames())->toBe([]);
});

it('offers the resend control on the launchpad while the environment is held back', function (): void {
    rootForResend();
    signUpForResend();

    // The banner's own state. It is a PROP now rather than copy in the response: the page
    // renders in the browser, and `awaitingVerification` is the single thing that decides
    // whether the way back exists at all.
    $this->get(route('projects'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/projects/index')
            ->where('awaitingVerification', true)
            ->where('verificationEmail', 'dana@acme.example'));
});

it('refuses the fourth resend inside the window', function (): void {
    rootForResend();
    signUpForResend();

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        resend();
        expect(lastResendNotice())->toContain('on its way to dana@acme.example');
    }

    resend();

    expect(lastResendNotice())
        ->toContain('That is a lot of emails.')
        ->not->toContain('on its way to dana@acme.example');

    // Three links, not four: the throttle refuses before anything is mailed.
    expect(Mail::sent(EmailVerificationMail::class))->toHaveCount(4); // 1 signup + 3 resends
});

it('says exactly the same thing whether or not the address is already confirmed', function (): void {
    rootForResend();
    $member = signUpForResend();

    resend();
    $unverifiedNotice = lastResendNotice();

    // Read something, or the comparison below passes on two empty strings — which is
    // exactly what a resend that flashed nothing at all would produce.
    expect($unverifiedNotice)->not->toBe('');

    // Confirm the address WITHOUT releasing an environment — the state a suspended or
    // at-limit account sits in, and the only place a resend could leak verification.
    $subjectId = $member->id;
    expect($subjectId)->toBeString();
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified((string) $subjectId, $member->email));

    $mailedSoFar = Mail::sent(EmailVerificationMail::class)->count();

    resend();
    $verifiedNotice = lastResendNotice();

    expect($verifiedNotice)->toBe($unverifiedNotice)
        // Nothing was actually mailed to an address that needs no confirming — the
        // silence is what makes the identical message honest rather than merely quiet.
        ->and(Mail::sent(EmailVerificationMail::class))->toHaveCount($mailedSoFar);
});

it('leaves exactly one live link: the resent one works and the earlier one does not', function (): void {
    rootForResend();
    $member = signUpForResend();

    resend();

    $links = verificationLinks();
    expect($links)->toHaveCount(2);

    [$original, $resent] = $links;
    expect($original)->not->toBe($resent);

    // The superseded link is dead — it provisions nothing.
    $this->get($original)->assertRedirect(route('login'));
    expect(environmentsOwnedBy((string) app(PlatformRoot::class)->run(fn () => app(Memberships::class)->forUser($member->id)->first()?->organization_id))->exists())->toBeFalse();

    // The newest one is the one that works.
    $this->get($resent)->assertRedirect();
    expect(environmentsOwnedBy((string) app(PlatformRoot::class)->run(fn () => app(Memberships::class)->forUser($member->id)->first()?->organization_id))->count())->toBe(1);
});

it('is a harmless no-op once the environment has been released', function (): void {
    rootForResend();
    $member = signUpForResend();

    [$link] = verificationLinks();
    $this->get($link)->assertRedirect();
    expect(environmentsOwnedBy((string) app(PlatformRoot::class)->run(fn () => app(Memberships::class)->forUser($member->id)->first()?->organization_id))->count())->toBe(1);

    $mailedSoFar = Mail::sent(EmailVerificationMail::class)->count();

    resend()->assertSessionHasNoErrors();

    expect(lastResendNotice())->toContain('your environment is already up and running');

    expect(Mail::sent(EmailVerificationMail::class))->toHaveCount($mailedSoFar)
        ->and(environmentsOwnedBy((string) app(PlatformRoot::class)->run(fn () => app(Memberships::class)->forUser($member->id)->first()?->organization_id))->count())->toBe(1);
});
