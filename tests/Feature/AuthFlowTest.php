<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Mfa\TotpAuthenticator;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

// Signup validates against HaveIBeenPwned; keep tests offline + deterministic.
beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

function account(string $email = 'dana@acme.test', string $password = 'supersecret123'): array
{
    $subject = app(Subjects::class)->create($email, 'Dana Reeves', $password);
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.substr(md5($email), 0, 6)));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');

    return [$subject, $org];
}

it('signs up: creates org, owner and session, then lands on the dashboard', function () {
    Volt::test('auth.signup')
        ->set('organization', 'Acme Inc.')
        ->set('name', 'Dana Reeves')
        ->set('email', 'dana@acme.test')
        ->set('password', 'supersecret123')
        ->call('register')
        ->assertRedirect(route('dashboard'));

    $subject = app(Subjects::class)->findByEmail('dana@acme.test');
    expect($subject)->not->toBeNull()
        ->and(app(Memberships::class)->forUser($subject->id))->toHaveCount(1)
        ->and(session()->has(PlatformAuth::SESSION_KEY))->toBeTrue();
});

it('rejects signup when the email already exists', function () {
    account('taken@acme.test');

    Volt::test('auth.signup')
        ->set('organization', 'Dupe')->set('name', 'X')->set('email', 'taken@acme.test')->set('password', 'supersecret123')
        ->call('register')
        ->assertHasErrors('email');
});

it('logs in with a correct password', function () {
    account();

    Volt::test('auth.login')
        ->set('email', 'dana@acme.test')->set('password', 'supersecret123')
        ->call('login')
        ->assertRedirect(route('dashboard'));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeTrue();
});

it('rejects a wrong password without starting a session', function () {
    account();

    Volt::test('auth.login')
        ->set('email', 'dana@acme.test')->set('password', 'nope')
        ->call('login')
        ->assertHasErrors('email')
        ->assertNoRedirect();

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();
});

it('routes a password login to MFA when the user has confirmed TOTP', function () {
    [$subject] = account('mfa@acme.test');
    $enrollment = app(Mfa::class)->enrollTotp($subject->id, 'mfa@acme.test');
    // Confirm enrollment with a valid current code derived from the secret.
    $code = (new TotpAuthenticator)->codeAt($enrollment->secret, time());
    app(Mfa::class)->confirmTotp($subject->id, $code);

    Volt::test('auth.login')
        ->set('email', 'mfa@acme.test')->set('password', 'supersecret123')
        ->call('login')
        ->assertRedirect(route('mfa'));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();
});

it('throttles repeated failed password attempts', function () {
    account();

    foreach (range(1, 5) as $ignored) {
        Volt::test('auth.login')->set('email', 'dana@acme.test')->set('password', 'wrong')->call('login');
    }

    Volt::test('auth.login')
        ->set('email', 'dana@acme.test')->set('password', 'wrong')
        ->call('login')
        ->assertHasErrors('email');

    // Even the correct password is blocked while throttled.
    Volt::test('auth.login')
        ->set('email', 'dana@acme.test')->set('password', 'supersecret123')
        ->call('login')
        ->assertNoRedirect();
});

it('redirects guests away from the console', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->get('/members')->assertRedirect(route('login'));
});

it('redirects authenticated users away from guest screens', function () {
    account();
    Volt::test('auth.login')->set('email', 'dana@acme.test')->set('password', 'supersecret123')->call('login');

    $this->get('/login')->assertRedirect(route('dashboard'));
});

it('applies strict security headers', function () {
    $response = $this->get('/login');

    $response->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'")
        ->and($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
});
