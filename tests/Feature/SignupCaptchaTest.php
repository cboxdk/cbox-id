<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Risk\Contracts\RiskScorer;
use Cbox\Risk\Enums\Outcome;
use Cbox\Risk\ValueObjects\RiskAssessment;
use Cbox\Risk\ValueObjects\RiskContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;

/**
 * The CAPTCHA is RISK-TRIGGERED, not universal: it appears only on a signup the scorer
 * already put at Challenge/StepUp, and only when the operator configured Turnstile.
 * These tests pin all three states — challenged, clean, and unconfigured.
 */
const SITEVERIFY = 'challenges.cloudflare.com/turnstile/v0/siteverify';

beforeEach(function (): void {
    Mail::fake();

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

/**
 * @param  array<string, mixed>  $overrides
 */
function attemptSignup(array $overrides = []): Testable
{
    $component = Volt::test('auth.signup')
        ->set('organization', 'Acme')
        ->set('name', 'Dana Reeves')
        ->set('email', 'dana@acme.example')
        ->set('password', 'a-strong-unbreached-passphrase');

    foreach ($overrides as $property => $value) {
        $component->set($property, $value);
    }

    return $component->call('register');
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

    $component = attemptSignup();

    $component->assertHasErrors('email')
        ->assertRenderedNotRedirected()
        // The widget is now on the page, so the human can actually satisfy the demand.
        ->assertSet('challenged', true);

    expect(app(Subjects::class)->findByEmail('dana@acme.example'))->toBeNull();
    Mail::assertNothingSent();
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'siteverify'));
});

it('lets a challenged signup through once the CAPTCHA token verifies', function (): void {
    fakeHttp(captchaPasses: true);
    scoreEvery(Outcome::Challenge);

    attemptSignup(['turnstileToken' => 'a-widget-token'])->assertHasNoErrors();

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

    attemptSignup(['turnstileToken' => 'a-replayed-token'])
        ->assertHasErrors('email')
        ->assertRenderedNotRedirected();

    expect(app(Subjects::class)->findByEmail('dana@acme.example'))->toBeNull();
    Mail::assertNothingSent();
});

it('never challenges a low-risk signup', function (): void {
    fakeHttp(captchaPasses: false); // would refuse if it were ever consulted
    scoreEvery(Outcome::Allow);

    attemptSignup()->assertHasNoErrors()->assertSet('challenged', false);

    expect(app(Subjects::class)->findByEmail('dana@acme.example'))->not->toBeNull();
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'siteverify'));
});

it('is inert when no Turnstile keys are configured', function (): void {
    fakeHttp(captchaPasses: false);
    config(['services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);
    scoreEvery(Outcome::Challenge);

    // Same challenged outcome as the first test — but with no keys, signup behaves
    // exactly as it did before the feature existed.
    attemptSignup()->assertHasNoErrors()->assertSet('challenged', false);

    expect(app(Subjects::class)->findByEmail('dana@acme.example'))->not->toBeNull();
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'siteverify'));
});

it('opens the CSP to Cloudflare only when Turnstile is configured', function (): void {
    $csp = fn (): string => (string) $this->get('/login')->headers->get('Content-Security-Policy');

    // Asserted per-directive rather than as one contiguous string. The original spelled
    // out `script-src 'self' 'unsafe-eval' https://challenges.cloudflare.com` verbatim,
    // so adding an unrelated source to the SAME directive — the CSP nonce Cloudflare's
    // JavaScript Detections needs — failed a test about Turnstile. What matters here is
    // which sources the directive names, not what order they sit in.
    $scriptSrc = fn (): string => collect(explode(';', $csp()))
        ->map(fn (string $part): string => trim($part))
        ->first(fn (string $part): bool => str_starts_with($part, 'script-src ')) ?? '';

    expect($scriptSrc())->toContain('https://challenges.cloudflare.com')
        ->and($csp())->toContain("frame-src 'self' https://challenges.cloudflare.com");

    config(['services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);

    expect($scriptSrc())->toContain("'self'")
        ->and($scriptSrc())->toContain("'unsafe-eval'")
        ->and($csp())->not->toContain('cloudflare')
        ->and($csp())->not->toContain('frame-src');
});
