<?php

declare(strict_types=1);

use App\Mail\EmailVerificationMail;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/** The organization a signed-up owner belongs to, read in the platform root. */
function ownerOrganizationId(string $subjectId): string
{
    return (string) app(PlatformRoot::class)->run(
        fn () => app(Memberships::class)->forUser($subjectId)->first()?->organization_id,
    );
}

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
    config(['cbox-id.environments.base_domains' => ['cboxid.com'], 'cbox-id.tenancy.multi_tenant' => true]);

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
    test()->from(route('signup'))->post(route('signup.register'), ['organization' => 'Acme', 'name' => 'Dana Reeves', 'email' => $email, 'password' => 'a-strong-unbreached-passphrase'])
        ->assertSessionHasNoErrors();
}

it('does not create product resources when a person verifies their email', function (): void {
    rootForDeferredSignup();
    signUpForWorkspace();
    $person = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('dana@acme.example'));
    expect($person)->not->toBeNull()->and(ownerOrganizationId($person->id))->toBe('');
    $url = null;
    Mail::assertSent(EmailVerificationMail::class, function (EmailVerificationMail $mail) use (&$url): bool {
        $url = $mail->url;

        return true;
    });
    $this->post($url)->assertRedirect();
    expect(Project::query()->count())->toBe(0)
        ->and(Environment::query()->where('is_default', false)->count())->toBe(0);
});

it('keeps replayed identity confirmation from provisioning a product', function (): void {
    rootForDeferredSignup();
    signUpForWorkspace();
    $url = null;
    Mail::assertSent(EmailVerificationMail::class, function (EmailVerificationMail $mail) use (&$url): bool {
        $url = $mail->url;

        return true;
    });
    $this->post($url)->assertRedirect();
    $this->post($url)->assertRedirect();
    expect(Project::query()->count())->toBe(0)
        ->and(Environment::query()->where('is_default', false)->count())->toBe(0);
});

it('lets a person without a team resend confirmation from their account page', function (): void {
    rootForDeferredSignup();
    signUpForWorkspace();
    config(['cbox-id.tenancy.account_host' => 'localhost']);
    $this->get(route('account'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('auth.user.confirmUrl', '/account/email/confirmation'));
    $this->from(route('account'))->post(route('account.email.confirmation'), ['email' => 'someone-else@example.test'])
        ->assertRedirect(route('account'))->assertSessionHasNoErrors()->assertSessionHas('status');
    Mail::assertSent(EmailVerificationMail::class, 2);
    Mail::assertNotSent(EmailVerificationMail::class, fn ($mail): bool => $mail->hasTo('someone-else@example.test'));
    expect(Project::query()->count())->toBe(0);
});

it('verifies a metered signup without granting an unpurchased environment', function (): void {
    rootForDeferredSignup();
    config(['cbox-id.entitlements.mode' => 'metered']);
    signUpForWorkspace('metered-signup@example.test');
    $member = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('metered-signup@example.test'));
    $org = ownerOrganizationId($member->id);
    $project = Project::query()->where('organization_id', $org)->sole();
    expect($project->environment_limit)->toBe(0);
    $url = null;
    Mail::assertSent(EmailVerificationMail::class, function (EmailVerificationMail $mail) use (&$url): bool {
        $url = $mail->url;

        return true;
    });
    $this->post($url)->assertRedirect();
    expect(environmentsOwnedBy($org)->count())->toBe(0);
    $this->get(route('projects'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('awaitingVerification', false)->where('billingHref', route('billing')));
});
