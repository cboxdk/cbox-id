<?php

declare(strict_types=1);

use App\Mail\EmailVerificationMail;
use Cbox\Id\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 * A console write refused for an unconfirmed address says so ON THE FORM.
 *
 * Found on cboxid.com: the workspace owner set up Google on the root console and got a bare
 * "Access denied" page — the gate's sentence ("Confirm your email address before you add a
 * sign-in provider…") was thrown away by the generic 403 page, so nothing said that one
 * click in their inbox was the whole fix.
 */
beforeEach(function (): void {
    multiTenantDeployment('cboxid.com');
});

function rootOwnerPostsGoogle(bool $verified): TestResponse
{
    ['subjectId' => $subjectId] = provisionAccount();

    if ($verified) {
        User::query()->withoutGlobalScopes()->whereKey($subjectId)->update(['email_verified_at' => now()]);
    }

    signInAsMember($subjectId);
    $form = 'https://cboxid.com/social-sign-in?provider=google';
    $reserved = test()->get($form)->assertOk()->inertiaProps('template.reservedId');

    return test()->from($form)->post('https://cboxid.com/social-sign-in', [
        'provider' => 'google',
        'clientId' => 'client.apps.googleusercontent.com',
        'clientSecret' => 'a-client-secret',
        'reservedId' => $reserved,
    ]);
}

it('sends an unconfirmed owner back to the form with the sentence that says what to do', function (): void {
    rootOwnerPostsGoogle(verified: false)
        ->assertRedirect('https://cboxid.com/social-sign-in?provider=google')
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Confirm your email address before you add a sign-in provider') && str_contains($message, 'Send confirmation link'));
});

it('answers an Inertia visit the same way, never with the generic 403 page', function (): void {
    ['subjectId' => $subjectId] = provisionAccount();
    signInAsMember($subjectId);

    test()->from('https://cboxid.com/social-sign-in?provider=google')
        ->post('https://cboxid.com/social-sign-in', ['provider' => 'google', 'clientId' => 'x', 'clientSecret' => 'y'], ['X-Inertia' => 'true'])
        ->assertStatus(303)
        ->assertSessionHas('error');
});

it('lets a confirmed owner past the gate', function (): void {
    // Google's discovery document is out of reach here; what matters is that the refusal,
    // if any, is not the email gate's.
    Http::fake(['*' => Http::response('', 503)]);

    $response = rootOwnerPostsGoogle(verified: true)->assertRedirect();

    expect((string) session('error'))->not->toContain('Confirm your email address');
});

it('shows an unconfirmed owner the banner, with a button that mails the link', function (): void {
    Mail::fake();
    ['subjectId' => $subjectId] = provisionAccount();
    signInAsMember($subjectId);

    // Every console page carries it — the person learns before a form refuses them.
    $page = test()->get('https://cboxid.com/social-sign-in')->assertOk();
    expect($page->inertiaProps('auth.user.emailVerified'))->toBeFalse()
        ->and($page->inertiaProps('auth.user.confirmUrl'))->toBe('/account/email/confirmation');

    test()->from('https://cboxid.com/social-sign-in')
        ->post('https://cboxid.com/account/email/confirmation')
        ->assertRedirect('https://cboxid.com/social-sign-in')
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'We sent a confirmation link'));

    // A provisioned workspace: the signup resend would have answered "already provisioned"
    // and sent nothing. This sends.
    Mail::assertSent(EmailVerificationMail::class);
});

it('tells the API caller nothing was needed once the address is confirmed', function (): void {
    ['subjectId' => $subjectId] = provisionAccount();
    User::query()->withoutGlobalScopes()->whereKey($subjectId)->update(['email_verified_at' => now()]);
    signInAsMember($subjectId);

    test()->from('https://cboxid.com/social-sign-in')
        ->post('https://cboxid.com/account/email/confirmation')
        ->assertSessionHas('status', 'Your email address is already confirmed.');

    expect(test()->get('https://cboxid.com/social-sign-in')->inertiaProps('auth.user.confirmUrl'))->toBeNull();
});
