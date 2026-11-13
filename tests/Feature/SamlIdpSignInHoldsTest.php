<?php

declare(strict_types=1);

use App\Platform\IntendedUrl;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\PasswordRevocationScope;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\ValueObjects\AdminPasswordAssignment;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\SamlIdp\Models\SamlIdpSession;
use Cbox\Id\SamlIdp\Testing\InteractsWithSamlIdp;
use Illuminate\Testing\TestResponse;

uses(InteractsWithSamlIdp::class);

/**
 * A SIGNED ASSERTION IS HELD WHERE THE CONSOLE HOLDS THE SAME SESSION.
 *
 * The SAML IdP's SSO endpoint is not behind the auth middleware, so it asked nothing the
 * middleware asks: a password-only session owing a second factor, a forced password change
 * or a mandated enrolment was given a signed assertion for any SP it could reach, while the
 * console revoked or held that very session.
 */
function heldSubject(string $email = 'held@sp-test.example'): string
{
    $subjects = app(Subjects::class);
    $subject = $subjects->create($email, 'Held Person', 'a-strong-unbreached-passphrase');
    $subjects->markEmailVerified($subject->id, $email);

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'held-'.substr(md5($email), 0, 8)));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

    return $subject->id;
}

/** @param list<string> $amr */
function heldSession(string $subjectId, array $amr = ['pwd']): string
{
    $membership = app(Memberships::class)->forUser($subjectId)->first();

    return app(SessionManager::class)->start($subjectId, $membership?->organization_id, $amr)->id;
}

function heldSso(string $sessionId, string $samlRequest): TestResponse
{
    return test()->withSession([PlatformAuth::SESSION_KEY => $sessionId])
        ->get('/sso/saml/idp/sso?'.http_build_query(['SAMLRequest' => $samlRequest]));
}

it('challenges a password-only session for the second factor the person enrolled, and asserts nothing', function (): void {
    $sp = $this->registerSamlServiceProvider();
    $id = heldSubject();
    $sessionId = heldSession($id);

    // The authenticator came after the session started.
    $enrolment = app(Mfa::class)->enrollTotp($id, 'held@sp-test.example');
    confirmTotpForTest($id, $enrolment->secret);

    $response = heldSso($sessionId, $this->makeRedirectAuthnRequest($sp->entity_id));

    $response->assertRedirect(route('mfa'));
    $response->assertDontSee('name="SAMLResponse"', false);
    expect(SamlIdpSession::query()->count())->toBe(0)
        ->and(Session::query()->whereKey($sessionId)->value('revoked_at'))->not->toBeNull()
        // The request waits for the second factor, which resumes it.
        ->and(session('cbox.saml_idp_pending'))->not->toBeNull();
});

it('holds a session that owes a forced password change, and comes back after it', function (): void {
    $sp = $this->registerSamlServiceProvider();
    $id = heldSubject();

    app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $id,
        password: 'the-handed-over-passphrase',
        temporary: true,
        expiresAt: null,
        revoke: PasswordRevocationScope::Nothing,
    ));

    $response = heldSso(heldSession($id), $this->makeRedirectAuthnRequest($sp->entity_id));

    $response->assertRedirect(route('password.change'));
    $response->assertDontSee('name="SAMLResponse"', false);
    expect(SamlIdpSession::query()->count())->toBe(0)
        ->and(session(IntendedUrl::KEY))->toBe(route('sso.saml.idp.sso'));
});

it('holds a session whose organization requires a second factor nobody has enrolled', function (): void {
    $sp = $this->registerSamlServiceProvider();
    $id = heldSubject();
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Required));

    $response = heldSso(heldSession($id), $this->makeRedirectAuthnRequest($sp->entity_id));

    $response->assertRedirect(route('account'));
    expect(SamlIdpSession::query()->count())->toBe(0);
});

it('still asserts a session that owes nothing', function (): void {
    $sp = $this->registerSamlServiceProvider();
    $id = heldSubject();

    heldSso(heldSession($id), $this->makeRedirectAuthnRequest($sp->entity_id))
        ->assertOk()
        ->assertSee('name="SAMLResponse"', false);
});

it('asserts a session that already proved the second factor', function (): void {
    $sp = $this->registerSamlServiceProvider();
    $id = heldSubject();
    $enrolment = app(Mfa::class)->enrollTotp($id, 'held@sp-test.example');
    confirmTotpForTest($id, $enrolment->secret);

    heldSso(heldSession($id, ['pwd', 'otp', 'mfa']), $this->makeRedirectAuthnRequest($sp->entity_id))
        ->assertOk()
        ->assertSee('name="SAMLResponse"', false);
});
