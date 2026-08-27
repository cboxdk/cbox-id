<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Risk\Contracts\RiskScorer;
use Cbox\Risk\Enums\Outcome;
use Cbox\Risk\ValueObjects\RiskAssessment;
use Cbox\Risk\ValueObjects\RiskContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

/**
 * The CAPTCHA is RISK-TRIGGERED, not universal: it appears only on a signup the scorer
 * already put at Challenge/StepUp, and only when the operator configured Turnstile.
 * These tests pin all three states — challenged, clean, and unconfigured.
 */
const SITEVERIFY = 'challenges.cloudflare.com/turnstile/v0/siteverify';

beforeEach(function (): void {
    Mail::fake();

    // A deployment that has been installed. `/signup` on a bare install redirects to
    // first-run, and that 302 is not the refusal any of these tests is about.
    installedDeployment();

    // Risk outcomes are only acted on under enforcement.
    config(['risk.mode' => 'enforce']);
    config([
        'services.turnstile.site_key' => '1x00000000000000000000AA',
        'services.turnstile.secret_key' => 'test-secret',
    ]);
});

/**
 * Pin the risk scorer's verdict, so these tests exercise the CAPTCHA branch rather than
 * the signal pipeline (which has its own coverage, and whose score depends on the
 * environment the suite runs in).
 */
function scoreEvery(Outcome $outcome): void
{
    app()->instance(RiskScorer::class, new class($outcome) implements RiskScorer
    {
        public function __construct(private readonly Outcome $outcome) {}

        public function assess(RiskContext $context): RiskAssessment
        {
            return new RiskAssessment(50.0, $this->outcome, []);
        }
    });
}

/** HIBP is faked everywhere; Turnstile's answer is the parameter. */
function fakeHttp(bool $captchaPasses): void
{
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('', 200),
        SITEVERIFY => Http::response(['success' => $captchaPasses, 'error-codes' => $captchaPasses ? [] : ['invalid-input-response']]),
    ]);
}

it('refuses a challenged signup that carries no CAPTCHA token, with a field error', function (): void {
    fakeHttp(captchaPasses: true); // irrelevant — no token means Cloudflare is never asked
    scoreEvery(Outcome::Challenge);

    attemptSignup(['email' => 'dana@acme.example'])
        ->assertRedirect(route('signup'))
        ->assertSessionHasErrors('email');

    // THE WIDGET IS NOW ON THE PAGE, so the human can actually satisfy the demand. On the
    // flash channel and read from the page the redirect lands on: a refusal that demanded
    // a CAPTCHA without drawing one is a door with no handle.
    test()->get(route('signup'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->hasFlash('challenged', true));

    expect(app(Subjects::class)->findByEmail('dana@acme.example'))->toBeNull();
    Mail::assertNothingSent();
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'siteverify'));
});

it('lets a challenged signup through once the CAPTCHA token verifies', function (): void {
    fakeHttp(captchaPasses: true);
    scoreEvery(Outcome::Challenge);

    attemptSignup(['email' => 'dana@acme.example', 'turnstileToken' => 'a-widget-token'])
        ->assertSessionHasNoErrors();

    expect(app(Subjects::class)->findByEmail('dana@acme.example'))->not->toBeNull();

    // The token was verified SERVER-SIDE, with the secret — never trusted from the client.
    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'siteverify')
            && $request['secret'] === 'test-secret'
            && $request['response'] === 'a-widget-token';
    });
});

it('refuses a challenged signup whose token Cloudflare rejects', function (): void {
    fakeHttp(captchaPasses: false);
    scoreEvery(Outcome::Challenge);

    attemptSignup(['email' => 'dana@acme.example', 'turnstileToken' => 'a-replayed-token'])
        ->assertRedirect(route('signup'))
        ->assertSessionHasErrors('email');

    expect(app(Subjects::class)->findByEmail('dana@acme.example'))->toBeNull();
    Mail::assertNothingSent();
});

it('never challenges a low-risk signup', function (): void {
    fakeHttp(captchaPasses: false); // would refuse if it were ever consulted
    scoreEvery(Outcome::Allow);

    // NOT CHALLENGED, which is proved by the two facts either side of it: the account
    // exists, and Cloudflare was never consulted. There is no page left to read the flash
    // off — registering signs the person in, and `/signup` is guest-only.
    attemptSignup(['email' => 'dana@acme.example'])->assertSessionHasNoErrors();

    expect(app(Subjects::class)->findByEmail('dana@acme.example'))->not->toBeNull();
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'siteverify'));
});

it('is inert when no Turnstile keys are configured', function (): void {
    fakeHttp(captchaPasses: false);
    config(['services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);
    scoreEvery(Outcome::Challenge);

    // Same challenged outcome as the first test — but with no keys, signup behaves
    // exactly as it did before the feature existed.
    // NOT CHALLENGED, which is proved by the two facts either side of it: the account
    // exists, and Cloudflare was never consulted. There is no page left to read the flash
    // off — registering signs the person in, and `/signup` is guest-only.
    attemptSignup(['email' => 'dana@acme.example'])->assertSessionHasNoErrors();

    expect(app(Subjects::class)->findByEmail('dana@acme.example'))->not->toBeNull();
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'siteverify'));
});

it('opens the CSP to Cloudflare only when Turnstile is configured', function (): void {
    $csp = fn (): string => (string) $this->get('/login')->headers->get('Content-Security-Policy');

    // Asserted per-directive rather than as one contiguous string. The original spelled the
    // whole directive out verbatim, so adding an unrelated source to it — the CSP nonce
    // Cloudflare's JavaScript Detections needs — failed a test about Turnstile. What matters
    // here is which sources the directive names, not what order they sit in.
    $scriptSrc = fn (): string => collect(explode(';', $csp()))
        ->map(fn (string $part): string => trim($part))
        ->first(fn (string $part): bool => str_starts_with($part, 'script-src ')) ?? '';

    expect($scriptSrc())->toContain('https://challenges.cloudflare.com')
        ->and($csp())->toContain("frame-src 'self' https://challenges.cloudflare.com");

    config(['services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);

    expect($scriptSrc())->toContain("'self'")
        /*
         * AND NOT 'unsafe-eval', which this used to assert was present. It was there for
         * Livewire's bundled Alpine, which evaluates its `x-` expressions with
         * `new Function` — `eval` as far as CSP is concerned — so every console page had to
         * permit dynamic code generation to render a dropdown. React compiles its templates
         * at build time, so the directive went out with the last Volt page. Asserted rather
         * than merely dropped: this is the kind of thing that comes back by accident.
         */
        ->and($scriptSrc())->not->toContain('unsafe-eval')
        ->and($csp())->not->toContain('cloudflare')
        ->and($csp())->not->toContain('frame-src');
});
