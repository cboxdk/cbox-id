<?php

declare(strict_types=1);

use App\Mail\EmailVerificationMail;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

/**
 * The environment — the routable, key-bearing IdP — is the expensive half of a signup,
 * and it is what five of eight real production accounts got for free while never once
 * verifying an address. These tests pin the gate: no verified email, no environment.
 */
beforeEach(function (): void {
    // Signup screens the password against HaveIBeenPwned — keep it offline.
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    Mail::fake();
});

/** The platform root, served on this test's host — how cboxid.com resolves (Tier 2). */
function rootForDeferredSignup(): Environment
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

function signUpForWorkspace(string $email = 'dana@acme.example'): void
{
    Volt::test('auth.signup')
        ->set('organization', 'Acme')
        ->set('name', 'Dana Reeves')
        ->set('email', $email)
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('register')
        ->assertHasNoErrors();
}

it('provisions no environment until the owner verifies their email, then exactly one', function (): void {
    rootForDeferredSignup();

    signUpForWorkspace();

    $member = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('dana@acme.example'));
    expect($member)->not->toBeNull()
        // The account exists and is usable, but owns nothing routable yet.
        ->and(environmentsOwnedBy($member->account_id)->exists())->toBeFalse();

    // The verification link is the only way to the environment.
    $url = null;
    Mail::assertSent(EmailVerificationMail::class, function (EmailVerificationMail $mail) use (&$url): bool {
        $url = $mail->url;

        return true;
    });

    expect($url)->toBeString();

    $this->get($url)->assertRedirect();

    $environments = environmentsOwnedBy($member->account_id)->get();
    expect($environments)->toHaveCount(1)
        ->and($environments->first()->name)->toBe('Production')
        ->and($environments->first()->is_default)->toBeFalse();
});

it('tells the owner on the workspace launchpad why there is no environment yet', function (): void {
    rootForDeferredSignup();

    signUpForWorkspace();

    // Without this the page shows a project with no environments and no explanation.
    // Asserted on the banner's OWN copy, not on the shared flash toast — the toast says
    // something similar and would keep this test green with the banner gone.
    $this->get(route('projects'))
        ->assertOk()
        ->assertSee('is created the moment you open it')
        ->assertSee('dana@acme.example');
});

it('does not mint a second environment when the verification link is replayed', function (): void {
    rootForDeferredSignup();

    signUpForWorkspace();

    $member = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('dana@acme.example'));

    $url = null;
    Mail::assertSent(EmailVerificationMail::class, function (EmailVerificationMail $mail) use (&$url): bool {
        $url = $mail->url;

        return true;
    });

    $this->get($url)->assertRedirect();
    $this->get($url)->assertRedirect();

    expect(environmentsOwnedBy($member->account_id)->count())->toBe(1);
});

it('still homes the account in the platform root while the environment is deferred', function (): void {
    $root = rootForDeferredSignup();

    signUpForWorkspace();

    $member = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('dana@acme.example'));

    // The member is a real subject in the platform root (the credential of record) and
    // their account has its home organization — everything except the IdP itself.
    expect($member?->subject_id)->not->toBeNull()
        ->and($member?->organization_id)->not->toBeNull()
        ->and($root->is_default)->toBeTrue();
});
