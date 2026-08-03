<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateAccountMember;
use App\Platform\Enums\AttemptOutcome;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\PasswordRevocationScope;
use Cbox\Id\Identity\ValueObjects\AdminPasswordAssignment;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * `AdminPasswordAssignment` documents `temporary: true` as "the subject MUST choose a
 * new password at next sign-in". Until this, `requiresChange()` was read in exactly one
 * place — to render a line of prose on the admin's own console page — so a temporary
 * password with no expiry was simply a permanent one the administrator also knew.
 */
/** The organization the held subject belongs to, for a test that registers a client in it. */
function subjectOwingAChangeOrg(): string
{
    subjectOwingAChange();

    return Organization::query()->where('slug', 'acme-forced')->value('id') ?? '';
}

function subjectOwingAChange(bool $temporary = true): string
{
    $subject = app(Subjects::class)->create('dana@acme.test', 'Dana', 'the-handed-over-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-forced'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

    app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $subject->id,
        password: 'the-handed-over-passphrase',
        temporary: $temporary,
        // No expiry: "until they change it". Precisely the combination that used to
        // produce a permanent administrator-known credential.
        expiresAt: null,
        revoke: PasswordRevocationScope::Nothing,
    ));

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return $subject->id;
}

it('holds every authenticated page until the temporary password is replaced', function (): void {
    subjectOwingAChange();

    // Not just the sign-in that used it — any authenticated request.
    $this->get('/dashboard')->assertRedirect(route('password.change'));
    $this->get('/account')->assertRedirect(route('password.change'));
    $this->get('/members')->assertRedirect(route('password.change'));

    // The change page itself must not redirect to itself.
    $this->get(route('password.change'))->assertOk();
});

it('lets a permanent administrative password through', function (): void {
    subjectOwingAChange(temporary: false);

    $this->get('/dashboard')->assertOk();
});

it('releases the hold once a new password is set, and only then', function (): void {
    $subjectId = subjectOwingAChange();

    // A refused password leaves the requirement standing — releasing the hold before
    // the write would let a policy rejection open the console anyway.
    Volt::test('auth.change-password')
        ->set('password', 'short')
        ->set('passwordConfirmation', 'short')
        ->call('save')
        ->assertHasErrors('password');

    expect(app(AdminPasswords::class)->requiresChange($subjectId))->toBeTrue();
    $this->get('/dashboard')->assertRedirect(route('password.change'));

    Volt::test('auth.change-password')
        ->set('password', 'a-passphrase-only-they-know')
        ->set('passwordConfirmation', 'a-passphrase-only-they-know')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(AdminPasswords::class)->requiresChange($subjectId))->toBeFalse()
        ->and(app(Subjects::class)->verifyPassword($subjectId, 'a-passphrase-only-they-know'))->toBeTrue();

    $this->get('/dashboard')->assertOk();
});

it('refuses a mismatched confirmation without touching the credential', function (): void {
    $subjectId = subjectOwingAChange();

    Volt::test('auth.change-password')
        ->set('password', 'a-passphrase-only-they-know')
        ->set('passwordConfirmation', 'a-different-passphrase-entirely')
        ->call('save')
        ->assertHasErrors('passwordConfirmation');

    expect(app(Subjects::class)->verifyPassword($subjectId, 'the-handed-over-passphrase'))->toBeTrue()
        ->and(app(AdminPasswords::class)->requiresChange($subjectId))->toBeTrue();
});

/**
 * OIDC Core §3.1.2.6: prompt=none must answer the CLIENT with error=login_required, not
 * redirect a user agent that was explicitly told not to interact. The authorize endpoint
 * makes that call; the hold must not pre-empt it.
 */
it('does not redirect a prompt=none authorization request', function (): void {
    subjectOwingAChange();

    $response = $this->get('/oauth/authorize?prompt=none&client_id=nope&response_type=code&redirect_uri=https://app.test/cb');

    expect((string) $response->headers->get('Location'))->not->toContain('password/change');
});

/**
 * The account plane is a separate gate ({@see AuthenticateAccountMember}),
 * so it needs its own proof. The credential of record is the member's SUBJECT — see
 * docs/core-concepts/unified-account-identity.md — which is why the requirement is read
 * and cleared inside the platform root.
 */
it('holds the workspace console until an account member replaces a temporary password', function (): void {
    platformRootEnvironment();

    $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    $subjectId = (string) $result->member->refresh()->subject_id;
    $root = app(PlatformRoot::class);

    $root->run(fn () => app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $subjectId,
        password: 'the-handed-over-passphrase',
        temporary: true,
        expiresAt: null,
        revoke: PasswordRevocationScope::Nothing,
    )));

    signInAsMember($result->member);

    $this->get(route('workspace.home'))->assertRedirect(route('workspace.password.change'));
    $this->get(route('workspace.password.change'))->assertOk();

    Volt::test('workspace.change-password')
        ->set('password', 'a-passphrase-only-they-know')
        ->set('passwordConfirmation', 'a-passphrase-only-they-know')
        ->call('save')
        ->assertHasNoErrors();

    expect($root->run(fn () => app(AdminPasswords::class)->requiresChange($subjectId)))->toBeFalse();

    $this->get(route('workspace.home'))->assertOk();
});

/**
 * `maxAgeDays` reaches the same hold as an administrative requirement. Enforcing rotation
 * at sign-in alone would let an already-open session outlive the rotation it was meant to
 * trigger.
 */
it('holds a subject whose password has outlived the policy max age', function (): void {
    $subject = app(Subjects::class)->create('rotate@acme.test', 'Rotate', 'a-perfectly-long-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-rotate'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(maxAgeDays: 30));

    // Age the PASSWORD, then sign in. Travelling with a session already open would only
    // expire the session, and the redirect to login would say nothing about rotation.
    $this->travel(31)->days();

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    $this->get('/dashboard')->assertRedirect(route('password.change'));

    // Choosing a new one restarts the clock and releases the hold.
    Volt::test('auth.change-password')
        ->set('password', 'a-freshly-chosen-passphrase')
        ->set('passwordConfirmation', 'a-freshly-chosen-passphrase')
        ->call('save')
        ->assertHasNoErrors();

    $this->get('/dashboard')->assertOk();
});

/**
 * A mandate cannot be enforced by refusing entry: that locks out precisely the people who
 * still have to enrol. They are held on the security page, which is where enrolment is.
 */
it('holds a subject with no second factor when the policy requires one', function (): void {
    $subject = app(Subjects::class)->create('needsmfa@acme.test', 'Needs', 'a-perfectly-long-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-mfa'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Required));

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    $this->get('/dashboard')->assertRedirect(route('account'));

    // The page they are sent to must be reachable, and so must the step-up it needs.
    $this->get(route('account'))->assertOk();
    $this->get(route('sudo'))->assertOk();
});

/**
 * The lockout is per SUBJECT and checked BEFORE the credential — a locked account that
 * still distinguished a right guess from a wrong one would be an oracle.
 */
it('locks an account out of the password door at the policy threshold', function (): void {
    $subject = app(Subjects::class)->create('guessed@acme.test', 'Guessed', 'the-real-passphrase-here');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-lock'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(lockoutThreshold: 3));

    $auth = app(PlatformAuth::class);
    $request = request();

    foreach (range(1, 3) as $ignored) {
        expect($auth->attemptPassword($request, 'guessed@acme.test', 'a-wrong-guess-entirely'))
            ->toBe(AttemptOutcome::Invalid);
    }

    // The RIGHT password is now refused too, and refused identically.
    expect($auth->attemptPassword($request, 'guessed@acme.test', 'the-real-passphrase-here'))
        ->toBe(AttemptOutcome::Invalid);

    $this->travel(16)->minutes();

    expect($auth->attemptPassword($request, 'guessed@acme.test', 'the-real-passphrase-here'))
        ->toBe(AttemptOutcome::Ok);
});

/**
 * The console is not the thing worth protecting — it is the door to the thing.
 *
 * Both holds sat behind `! $optional`, and /oauth/authorize is the one route that runs
 * in optional mode. So an organization's own sign-in rules were enforced on the admin UI
 * and skipped on the endpoint that mints access and refresh tokens: a member held on the
 * account page for not enrolling a second factor could open any connected app and — for
 * a first-party client, with no consent screen at all — walk away with a token.
 *
 * The tell was already in the code. exemptFromHolds() carves out prompt=none with a
 * docblock saying the exemption exists because "the authorize endpoint makes that call".
 * That carve-out could never be reached from a route running in optional mode.
 */
/**
 * A REGISTERED client, deliberately.
 *
 * The first version of these tests used `client_id=whatever`, so the component refused
 * the unknown client and returned before ever reaching the hold — they passed without
 * testing anything, which is why the suite stayed green while the enforcement moved.
 */
function authorizeUrlForHeldSubject(string $orgId, array $extra = []): string
{
    $client = app(ClientRegistry::class)->register(new NewClient(
        'Held App',
        redirectUris: ['https://app.test/cb'],
        organizationId: $orgId,
    ))->client;

    return '/oauth/authorize?'.http_build_query(array_merge([
        'client_id' => $client->client_id,
        'response_type' => 'code',
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid',
        'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
        'code_challenge_method' => 'S256',
    ], $extra));
}

/**
 * The console is not the thing worth protecting — it is the door to the thing. An
 * organization's own sign-in rules must hold on the endpoint that mints tokens, or a
 * member held on the account page for not enrolling a second factor opens any connected
 * app and walks away with an access and refresh token.
 */
it('holds an authorization request from a subject who owes a password change', function (): void {
    $orgId = subjectOwingAChangeOrg();

    $this->get(authorizeUrlForHeldSubject($orgId))->assertRedirect(route('password.change'));
});

it('holds an authorization request from a subject who has not enrolled a second factor', function (): void {
    $subject = app(Subjects::class)->create('authz-nomfa@acme.test', 'No Factor', 'a-perfectly-long-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-authz-mfa'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Required));

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    $this->get(authorizeUrlForHeldSubject($org->id))->assertRedirect(route('account'));
});

/**
 * And prompt=none must be answered to the CLIENT, whichever binding carried it. The
 * middleware could only see the query string, so a silent-renew iframe using PAR or the
 * POST binding got the password-change page and its promise never resolved — the SDK then
 * signs the user out on every token refresh. Under `require_par`, PAR is the only legal
 * way to send prompt=none at all.
 */
it('answers a held prompt=none to the client rather than redirecting the browser', function (): void {
    $orgId = subjectOwingAChangeOrg();

    $location = (string) $this->get(authorizeUrlForHeldSubject($orgId, ['prompt' => 'none']))
        ->headers->get('Location');

    expect($location)->toStartWith('https://app.test/cb')
        ->and($location)->toContain('error=interaction_required')
        ->and($location)->not->toContain('password/change');
});

/**
 * The forced-change page must not be a password-SET endpoint for anyone who happens to
 * hold a session.
 *
 * The middleware only EXEMPTS this route from its own redirect, so the redirect does not
 * loop. An exemption is not a restriction: without a guard in the action, anyone with a
 * live session — a stolen cookie, or one obtained through a door that never asks for a
 * password — could choose a new password, and then use the password they had just chosen
 * to satisfy every step-up gate on the account.
 *
 * Both halves matter. Guarding only the administrative hold would lock out everyone held
 * by password rotation instead: the middleware sends them here and the page refuses to
 * let them leave.
 */
it('refuses a password change from someone who is not being held', function (): void {
    $subject = app(Subjects::class)->create('free@acme.test', 'Free', 'a-perfectly-long-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-free'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    // Nothing is holding this subject: no administrative password, no expired max age.
    $this->get('/dashboard')->assertOk();

    Volt::test('auth.change-password')
        ->set('password', 'a-password-the-attacker-picked')
        ->set('passwordConfirmation', 'a-password-the-attacker-picked')
        ->call('save')
        ->assertForbidden();

    expect(app(Subjects::class)->verifyPassword($subject->id, 'a-perfectly-long-passphrase'))
        ->toBeTrue('a session-only caller replaced the password of an account that owed no change');
});
