<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Mail\MagicLinkMail;
use App\Mail\PasswordResetMail;
use App\Platform\CurrentUser;
use App\Platform\IntendedUrl;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\NewInvitation;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\PasswordRevocationScope;
use Cbox\Id\Identity\ValueObjects\AdminPasswordAssignment;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

/**
 * An app sends a person to the hosted sign-in and expects them back. Whatever the person
 * has to do on the way (accept an invitation, replace a temporary password, enrol a second
 * factor, follow a mailed link, sign in again) ends in the authorization that was waiting.
 */
const RESUME_CALLBACK = 'https://billing.example.com/auth/callback';

beforeEach(fn () => installedDeployment());

function resumeApp(bool $firstParty = true, ?string $organizationId = null): string
{
    return app(ClientRegistry::class)->register(new NewClient(
        'Cbox Billing',
        ClientType::Public,
        redirectUris: [RESUME_CALLBACK],
        grantTypes: ['authorization_code', 'refresh_token'],
        scopes: ['openid', 'email'],
        organizationId: $organizationId,
        firstParty: $firstParty,
    ))->client->client_id;
}

function resumeAcme(): Organization
{
    return app(Organizations::class)->bySlug('acme') ?? app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));
}

/** A member of Acme with a password; signed in on this browser unless told otherwise. */
function resumeMember(string $email = 'pat@acme.test', bool $signedIn = true, int $sessionAgeSeconds = 0): string
{
    $subject = app(Subjects::class)->create($email, 'Pat Doe', 'supersecret123');
    app(Subjects::class)->markEmailVerified($subject->id, $email);
    $org = resumeAcme();
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    if ($signedIn) {
        $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);

        if ($sessionAgeSeconds > 0) {
            $session->created_at = now()->subSeconds($sessionAgeSeconds);
            $session->save();
        }

        session([PlatformAuth::SESSION_KEY => $session->id]);
        app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);
    }

    return $subject->id;
}

/** Start the app's authorization in this browser and return the address it resumes at. */
function resumePending(string $clientId, array $extra = [], ?string $expectAt = null): string
{
    $response = authorizeRequest(['client_id' => $clientId, 'redirect_uri' => RESUME_CALLBACK, ...$extra]);
    $response->assertRedirect($expectAt ?? route('login'));

    return (string) session(IntendedUrl::KEY);
}

/*
 * ---------------------------------------------------------------------------------------
 * Signing in again (`prompt=login`, `max_age`)
 * ---------------------------------------------------------------------------------------
 */

it('lets a signed-in person sign in again for prompt=login and resumes the authorization', function (): void {
    resumeMember();
    $resume = resumePending(resumeApp(), ['prompt' => 'login'], route('accounts.add'));

    $this->get(route('accounts.add'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/login')->where('signingInTo.name', 'Cbox Billing'));

    // The address step and the password step of the same page: neither may bounce a
    // signed-in browser to the console.
    $this->from(route('accounts.add'))->post(route('login.identify'), ['email' => 'pat@acme.test'])
        ->assertRedirect(route('accounts.add'));

    attemptLogin(['email' => 'pat@acme.test'])->assertRedirect($resume);

    expect(leftFor($this->get($resume)))->toStartWith(RESUME_CALLBACK.'?code=');
});

it('lets a signed-in person sign in again for max_age and resumes the authorization', function (): void {
    resumeMember(sessionAgeSeconds: 86_400);
    $resume = resumePending(resumeApp(), ['max_age' => '0'], route('accounts.add'));

    $this->get(route('accounts.add'))->assertOk();

    attemptLogin(['email' => 'pat@acme.test'])->assertRedirect($resume);
});

it('lets the passkey ceremony start on the sign-in-again page', function (): void {
    resumeMember();
    resumePending(resumeApp(), ['prompt' => 'login'], route('accounts.add'));

    $this->get(route('accounts.add'))->assertOk();

    $this->postJson(route('passkeys.login.options'))->assertOk()->assertJsonStructure(['challenge']);
});

it('still keeps a signed-in person who is not signing in again off the sign-in doors', function (): void {
    resumeMember();

    $this->get(route('login'))->assertRedirect(route('dashboard'));
    attemptLogin(['email' => 'pat@acme.test'])->assertRedirect(route('dashboard'));
});

/*
 * ---------------------------------------------------------------------------------------
 * Invitations
 * ---------------------------------------------------------------------------------------
 */

/** An invitation to Acme for `$email`, optionally sent on an app's behalf. Returns the mailed token. */
function resumeInvitation(string $email, ?string $clientId = null, ?string $returnTo = null): string
{
    Mail::fake();

    $owner = app(Subjects::class)->findByEmail('owner@acme.test') ?? app(Subjects::class)->create('owner@acme.test', 'Olga Owner', 'supersecret123');
    $acme = resumeAcme();
    app(Memberships::class)->add($acme->id, $owner->id, MembershipRole::Owner);

    app(OrganizationInvitations::class)->send(new NewInvitation(
        organizationId: $acme->id,
        email: $email,
        role: MembershipRole::Member,
        inviter: new Inviter($owner->id, 'Olga Owner'),
        clientId: $clientId,
        returnTo: $returnTo,
    ));

    // The token exists only in the mailed link.
    $mailed = Mail::sent(InvitationMail::class)->last() ?? Mail::queued(InvitationMail::class)->last();
    preg_match('#/invitations/([^/?]+)/accept#', (string) $mailed?->url, $link);

    return $link[1] ?? '';
}

it('resumes a pending authorization when an invitation is accepted in the same browser', function (): void {
    $clientId = resumeApp();
    $token = resumeInvitation('new@acme.test');
    $resume = resumePending($clientId);

    $this->post(route('invitation.accept.store', $token))->assertRedirect($resume);

    expect(session(IntendedUrl::KEY))->toBeNull()
        ->and(leftFor($this->get($resume)))->toStartWith(RESUME_CALLBACK.'?code=');
});

it('lands a cold invitation in the app it was sent for when it carries a return address', function (): void {
    $clientId = resumeApp();
    $token = resumeInvitation('new@acme.test', $clientId, 'https://billing.example.com/welcome');

    expect(leftFor(inertiaRequest(fn () => $this->post(route('invitation.accept.store', $token)))))
        ->toBe('https://billing.example.com/welcome');
});

it('lands a cold invitation that names only its app at the app', function (): void {
    $clientId = resumeApp();
    $token = resumeInvitation('new@acme.test', $clientId);

    expect(leftFor(inertiaRequest(fn () => $this->post(route('invitation.accept.store', $token)))))
        ->toBe('https://billing.example.com');
});

it('lands a cold invitation that names no app in the console', function (): void {
    $token = resumeInvitation('new@acme.test');

    $this->post(route('invitation.accept.store', $token))->assertRedirect(route('dashboard'));
});

/*
 * ---------------------------------------------------------------------------------------
 * The holds: a temporary password, a second factor to enrol
 * ---------------------------------------------------------------------------------------
 */

it('resumes the authorization after a forced password change', function (): void {
    $subjectId = resumeMember();

    app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $subjectId,
        password: 'the-handed-over-passphrase',
        temporary: true,
        expiresAt: null,
        revoke: PasswordRevocationScope::Nothing,
    ));

    $resume = resumePending(resumeApp(), expectAt: route('password.change'));

    $this->from(route('password.change'))->post(route('password.change.update'), [
        'password' => 'a-passphrase-only-they-know',
        'password_confirmation' => 'a-passphrase-only-they-know',
    ])->assertRedirect($resume);

    expect(leftFor($this->get($resume)))->toStartWith(RESUME_CALLBACK.'?code=');
});

it('still lands a forced password change with nothing pending in the console', function (): void {
    $subjectId = resumeMember();

    app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $subjectId,
        password: 'the-handed-over-passphrase',
        temporary: true,
        expiresAt: null,
        revoke: PasswordRevocationScope::Nothing,
    ));

    $this->from(route('password.change'))->post(route('password.change.update'), [
        'password' => 'a-passphrase-only-they-know',
        'password_confirmation' => 'a-passphrase-only-they-know',
    ])->assertRedirect(route('dashboard'));
});

it('offers the way back to the app on the page that holds a person for a second factor', function (): void {
    resumeMember();
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Required));

    $resume = resumePending(resumeApp(), expectAt: route('account'));

    $this->get(route('account'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('returnTo.url', route('app.continue'))
        ->where('returnTo.host', 'Cbox Billing'));

    // Still held until a factor is enrolled: the way on does not walk around the hold.
    $this->get(route('app.continue'))->assertRedirect(route('account'));
    expect(session(IntendedUrl::KEY))->toBe($resume);

    // Enrolled (here: the requirement lifted). The link follows the waiting request and
    // spends it, so a later sign-in in this browser is not sent to a request that was
    // already answered.
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Optional));

    $this->get(route('app.continue'))->assertRedirect($resume);
    expect(session(IntendedUrl::KEY))->toBeNull();
});

it('names no app on the security page of somebody who is not on their way to one', function (): void {
    resumeMember();

    $this->get(route('account'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('returnTo', null));
});

/*
 * ---------------------------------------------------------------------------------------
 * Mailed links: password reset and sign-in link
 * ---------------------------------------------------------------------------------------
 */

it('resumes the authorization after a password reset finished in the same browser', function (): void {
    resumeMember(signedIn: false);
    $resume = resumePending(resumeApp());

    $token = (string) app(PasswordReset::class)->request('pat@acme.test');

    $this->from(route('password.reset', $token))->post(route('password.update'), [
        'token' => $token,
        'password' => 'a-passphrase-only-they-know',
        'password_confirmation' => 'a-passphrase-only-they-know',
    ])->assertRedirect(route('login'));

    // Still on the way to the app, and the sign-in that follows resumes it.
    $this->get(route('login'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('signingInTo.name', 'Cbox Billing'));

    attemptLogin(['email' => 'pat@acme.test', 'password' => 'a-passphrase-only-they-know'])->assertRedirect($resume);
});

it('mails links that name the app, and only while an authorization is pending', function (): void {
    Mail::fake();
    resumeMember(signedIn: false);
    $clientId = resumeApp();

    $this->from(route('login'))->post(route('login.magic-link'), ['email' => 'pat@acme.test']);
    Mail::assertQueued(MagicLinkMail::class, fn (MagicLinkMail $mail): bool => ! str_contains($mail->url, 'app='));

    resumePending($clientId);

    $this->from(route('login'))->post(route('login.magic-link'), ['email' => 'pat@acme.test']);
    Mail::assertQueued(MagicLinkMail::class, fn (MagicLinkMail $mail): bool => str_contains($mail->url, 'app='.$clientId));

    $this->from(route('password.request'))->post(route('password.email'), ['email' => 'pat@acme.test']);
    Mail::assertQueued(PasswordResetMail::class, fn (PasswordResetMail $mail): bool => str_contains($mail->url, 'app='.$clientId));
});

it('sends a sign-in link opened in another browser on to the app it was asked for from', function (): void {
    resumeMember(signedIn: false);
    $clientId = resumeApp();

    $token = app(MagicLink::class)->request('pat@acme.test');

    // Another browser: no session, only the link.
    $this->get(route('magic.redeem', ['token' => $token, 'app' => $clientId]))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('confirmation.actionUrl', route('magic.redeem.store', ['token' => $token, 'app' => $clientId])));

    $this->post(route('magic.redeem.store', ['token' => $token, 'app' => $clientId]))->assertRedirect(route('app.continue'));

    expect(leftFor($this->get(route('app.continue'))))->toBe('https://billing.example.com');
});

it('sends a password reset finished in another browser on to the app after the sign-in', function (): void {
    resumeMember(signedIn: false);
    $clientId = resumeApp();

    $token = (string) app(PasswordReset::class)->request('pat@acme.test');

    $this->get(route('password.reset', ['token' => $token, 'app' => $clientId]))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('forApp', $clientId));

    $this->from(route('password.reset', $token))->post(route('password.update'), [
        'token' => $token,
        'app' => $clientId,
        'password' => 'a-passphrase-only-they-know',
        'password_confirmation' => 'a-passphrase-only-they-know',
    ])->assertRedirect(route('login'));

    attemptLogin(['email' => 'pat@acme.test', 'password' => 'a-passphrase-only-they-know'])->assertRedirect(route('app.continue'));

    expect(leftFor($this->get(route('app.continue'))))->toBe('https://billing.example.com');
});

it('follows a mailed link\'s app only to an app the environment itself registered', function (): void {
    resumeMember(signedIn: false);
    // An organization's own app: somebody outside the environment's administrators chose its address.
    $clientId = resumeApp(firstParty: false, organizationId: resumeAcme()->id);

    $token = app(MagicLink::class)->request('pat@acme.test');

    $this->post(route('magic.redeem.store', ['token' => $token, 'app' => $clientId]))->assertRedirect(route('dashboard'));
});

it('follows a mailed link that names no app the environment knows to the console', function (): void {
    resumeMember(signedIn: false);

    $token = app(MagicLink::class)->request('pat@acme.test');

    $this->post(route('magic.redeem.store', ['token' => $token, 'app' => 'no-such-client']))->assertRedirect(route('dashboard'));
});

it('sends a visit to the continue page with nothing waiting to the console', function (): void {
    resumeMember();

    $this->get(route('app.continue'))->assertRedirect(route('dashboard'));
});

/*
 * ---------------------------------------------------------------------------------------
 * No organization: the realm's own apps are never handed a person without one
 * ---------------------------------------------------------------------------------------
 */

/** The realm: the platform root of a multi-tenant deployment. */
function resumeRealm(bool $personalOrganizations): void
{
    config([
        'cbox-id.environments.base_domains' => ['cboxid.com'],
        'cbox-id.tenancy.multi_tenant' => true,
        'cbox-id.signup.personal_organization' => $personalOrganizations,
    ]);

    $root = Environment::query()->create(['name' => 'Production', 'slug' => 'production', 'status' => 'active', 'is_default' => true]);
    app(EnvironmentContext::class)->set($root);
}

function resumeLoner(): Subject
{
    $subject = app(Subjects::class)->create('pat@acme.test', 'Pat Doe', 'supersecret123');
    app(Subjects::class)->markEmailVerified($subject->id, 'pat@acme.test');
    signInAsSubject($subject->id);

    return $subject;
}

it('never mints a code with no organization for a first-party app, with the switch off too', function (): void {
    resumeRealm(personalOrganizations: false);
    $person = resumeLoner();
    $clientId = resumeApp();

    $resume = resumePending($clientId, expectAt: route('organization.required'));

    // The screen answers for this browser although the switch is off...
    $this->get(route('organization.required'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/no-organization'));

    // ...and "continue with a personal organization" founds one and resumes the request.
    $this->post(route('organization.required.personal'))->assertRedirect($resume);

    $organization = Organization::query()->where('slug', '!=', 'installed-deployment')->sole();

    expect(app(Memberships::class)->activeRole($organization->id, $person->id))->toBe(MembershipRole::Owner)
        ->and(leftFor($this->get($resume)))->toStartWith(RESUME_CALLBACK.'?code=');
});

it('answers a silent request from a first-party app with interaction_required, with the switch off too', function (): void {
    resumeRealm(personalOrganizations: false);
    resumeLoner();

    expect(leftFor(authorizeRequest(['client_id' => resumeApp(), 'redirect_uri' => RESUME_CALLBACK, 'prompt' => 'none'])))
        ->toContain('error=interaction_required');
});

it('has no screen for somebody nothing sent to it while the switch is off', function (): void {
    resumeRealm(personalOrganizations: false);
    resumeLoner();

    $this->get(route('organization.required'))->assertRedirect(route('dashboard'));
});

it('does not ask an operator for an organization', function (): void {
    resumeRealm(personalOrganizations: false);
    $operator = actAsOperator();

    expect($operator)->not->toBeNull();

    $response = authorizeRequest(['client_id' => resumeApp(), 'redirect_uri' => RESUME_CALLBACK]);

    expect($response->headers->get('Location'))->not->toBe(route('organization.required'));
});

/*
 * ---------------------------------------------------------------------------------------
 * With a login host
 * ---------------------------------------------------------------------------------------
 */

it('serves the continue page with the issuer: on the login host and a tenant\'s, never the app host', function (): void {
    loginHostMode();

    expect($this->get('https://'.LOGIN_HOST.'/continue')->getStatusCode())->not->toBe(404)
        ->and($this->get('https://'.TENANT_HOST.'/continue')->getStatusCode())->not->toBe(404)
        ->and($this->get('https://'.APP_HOST.'/continue')->getStatusCode())->toBe(404);
});

it('names the app on the login host\'s sign-in page and resumes there after sign-in', function (): void {
    loginHostMode();
    // The realm's own environment, where its apps and people are.
    app(EnvironmentContext::class)->set(Environment::query()->where('is_default', true)->sole());

    $clientId = resumeApp();
    $subject = app(Subjects::class)->create('pat@acme.test', 'Pat Doe', 'supersecret123');
    app(Memberships::class)->add(resumeAcme()->id, $subject->id, MembershipRole::Owner);

    $this->get('https://'.LOGIN_HOST.'/oauth/authorize?'.http_build_query([
        'client_id' => $clientId, 'redirect_uri' => RESUME_CALLBACK, 'response_type' => 'code', 'scope' => 'openid email',
        'state' => 'xyz', 'code_challenge' => pkceChallenge(), 'code_challenge_method' => 'S256', 'login_hint' => 'pat@acme.test',
    ]))->assertRedirect('https://'.LOGIN_HOST.'/login');

    $resume = (string) session(IntendedUrl::KEY);

    $this->get('https://'.LOGIN_HOST.'/login')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('signingInTo.name', 'Cbox Billing')
        ->where('email', 'pat@acme.test'));

    $this->from('https://'.LOGIN_HOST.'/login')->post('https://'.LOGIN_HOST.'/login', ['email' => 'pat@acme.test', 'password' => 'supersecret123'])
        ->assertRedirect($resume);

    expect($resume)->toStartWith('https://'.LOGIN_HOST.'/oauth/authorize');
});
