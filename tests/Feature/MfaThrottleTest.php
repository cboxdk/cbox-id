<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

/**
 * The five-attempt ceiling on the second factor is the ONLY thing bounding it.
 *
 * A TOTP code is six digits — a million-value space, and a space an automated client can
 * walk in minutes over `/livewire/update` if nothing counts. There is no second layer
 * underneath: `PlatformAuth::completeMfa()` does no counting, `MfaService` has no
 * limiter, and the `AuthPolicy` lockout threshold that the credential-hardening tests
 * exercise applies to PASSWORD verification only. So these four lines in the component
 * are the whole control, and until this file existed nothing in either suite failed when
 * they were deleted.
 *
 * The assertion that matters is the last one in each case: after the budget is spent, a
 * CORRECT code is still refused. A test that only checks the error message on a wrong
 * code passes just as happily with the throttle removed.
 */
function subjectWithTotp(string $email = 'mfa@acme.test'): array
{
    $subject = app(Subjects::class)->create($email, 'MFA User', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-mfa-throttle'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $enrollment = app(Mfa::class)->enrollTotp($subject->id, $email);

    // Confirm with the PREVIOUS time step — one back, which is inside the ±1 skew
    // window the verifier accepts. Confirming with the current step advances
    // `last_used_step` to it, and the replay guard then refuses that same step again:
    // the later "correct code" assertion would pass because of the replay guard rather
    // than the throttle, and the test would prove nothing about the thing it is named
    // after. It did exactly that until this line changed.
    app(Mfa::class)->confirmTotp($subject->id, app(TotpAuthenticator::class)->codeAt($enrollment->secret, time() - 30));

    return [$subject->id, $enrollment->secret];
}

beforeEach(function (): void {
    RateLimiter::clear('mfa|');
});

it('stops grinding the TOTP code, and keeps refusing after the budget is spent', function (): void {
    [$subjectId, $secret] = subjectWithTotp();

    test()->from(route('login'))->post(route('login.attempt'), ['email' => 'mfa@acme.test', 'password' => 'a-strong-unbreached-passphrase', 'identified' => true])
        ->assertRedirect(route('mfa'));

    for ($attempt = 0; $attempt < 6; $attempt++) {
        test()->from(route('mfa'))->post(route('mfa.verify'), ['code' => '000000']);
    }

    // The real code, and it must NOT get through: this is the assertion the throttle
    // exists for, and the one a message-only test would miss.
    test()->from(route('mfa'))
        ->post(route('mfa.verify'), ['code' => app(TotpAuthenticator::class)->codeAt($secret, time())])
        ->assertSessionHasErrors('code');

    expect(session()->has('cbox.session'))
        ->toBeFalse('a correct code was accepted after the attempt budget was spent');

    expect(RateLimiter::tooManyAttempts('mfa|'.$subjectId, 5))->toBeTrue();
});

it('stops grinding recovery codes on the same budget', function (): void {
    [$subjectId] = subjectWithTotp('recovery@acme.test');

    $codes = app(Mfa::class)->generateRecoveryCodes($subjectId);

    test()->from(route('login'))->post(route('login.attempt'), ['email' => 'recovery@acme.test', 'password' => 'a-strong-unbreached-passphrase', 'identified' => true])
        ->assertRedirect(route('mfa'));

    for ($attempt = 0; $attempt < 6; $attempt++) {
        test()->from(route('mfa'))->post(route('mfa.recover'), ['recoveryCode' => 'not-a-real-recovery-code']);
    }

    // A genuine, unused recovery code — still refused.
    test()->from(route('mfa'))
        ->post(route('mfa.recover'), ['recoveryCode' => $codes[0]])
        ->assertSessionHasErrors('recoveryCode');

    expect(session()->has('cbox.session'))
        ->toBeFalse('a valid recovery code was accepted after the attempt budget was spent');
});

/**
 * And every other door that verifies a second factor has to count too.
 *
 * The behavioural cases above cover the org plane. The workspace and operator planes
 * have their own MFA screens, sudo has one, and OTP step-up has another — each a
 * separate component with its own copy of the check. A per-plane behavioural test for
 * all of them is a lot of fixture for one line of logic, so this asserts the invariant
 * instead: a component that verifies a factor must reference a limiter.
 *
 * A string scan is weaker than driving the screen, and it is deliberately the second
 * line of defence rather than the first — but it is what catches a NEW plane being added
 * without one, which is how the console ended up with four copies of this in the first
 * place.
 */
it('rate-limits the second factor on every plane that checks one', function (): void {
    $verifiers = [];
    $checked = 0;

    /*
     * WHEREVER THE SCREENS LIVE — which, now the port is done, is controllers.
     *
     * This walked Volt views only, and three of the four planes it names were already
     * controllers: it found ONE of them and reported a clean house. The floor below is
     * what caught that, which is the entire reason the floor is here. The Volt roots are
     * gone with the directories; the module controllers stay, because a module shipping a
     * step-up screen is exactly the "new plane added without one" this exists to catch.
     */
    $roots = array_merge(
        [base_path('app/Http/Controllers')],
        array_filter((array) glob(base_path('modules/*/src/Http/Controllers')), 'is_dir'),
    );

    foreach ($roots as $root) {
        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) $file->getContents();

            // EVERY VERB THAT CHECKS A SECRET SOMEBODY TYPES, not the three names one
            // screen happened to use. The old list matched `auth/mfa` and nothing else,
            // so this sweep covered ONE of the four planes its own docblock names — and
            // would have said nothing about the other three losing their limiter.
            $checksAFactor = (bool) preg_match(
                '/->(completeMfa|completeMfaWithRecoveryCode|verifyTotp|verifyRecoveryCode|confirmTotp|completeOtpStepUp|verifyPassword)\(/',
                $source,
            );

            if (! $checksAFactor) {
                continue;
            }

            $checked++;

            /*
             * BOUNDED, by any mechanism this codebase actually uses — not by one class name.
             *
             * `RateLimiter` is the hosted form's answer: a bucket keyed on the identity and
             * the caller. The embedded Frontend API cannot use it as the whole answer,
             * because a cross-origin page carries no session and the pending state IS the
             * ticket — so the budget lives on that row and `claimAttempt()` spends it
             * atomically, before the code is checked, keyed to the publishable key that
             * minted it. That is a bound on guesses against ONE pending sign-in, which is
             * the thing being protected here.
             *
             * Named rather than excluded by path: a new screen may legitimately use either,
             * and the question is whether the guesses are counted at all.
             */
            $bounded = str_contains($source, 'RateLimiter')
                || str_contains($source, '->claimAttempt(');

            if (! $bounded) {
                $verifiers[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    // A FLOOR, because a detector that matches nothing reports a clean sweep. Four planes
    // check a factor today; this fails if the vocabulary drifts out from under it again.
    expect($checked)->toBeGreaterThanOrEqual(4, 'the detector stopped matching the screens it is meant to watch');

    expect($verifiers)->toBe([], 'these screens verify a second factor with nothing bounding the guesses');
});
