<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Lifecycle\EndOtherSessions;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\PasswordRevocationScope;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AdminPasswordAssignment;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Models\RefreshToken;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Str;

/**
 * WHEN A PERSON ENDS THEIR SESSIONS, THE APPS THOSE SESSIONS SIGNED IN TO END TOO.
 *
 * A password reset, a password change, "sign out every other session" and an SSO mandate
 * ended session rows. The refresh tokens those sessions had handed to apps kept rotating,
 * so whoever used a stolen password to authorize an app of their own kept it after the
 * owner reacted. What the person is using right now keeps working.
 */
function soeApp(string $name): Client
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: $name,
        type: ClientType::Public,
        redirectUris: ['https://'.strtolower($name).'.test/callback'],
        grantTypes: ['authorization_code', 'refresh_token'],
        scopes: ['openid', 'offline_access'],
        firstParty: true,
    ))->client;
}

function soeRefreshes(Client $app, string $refresh): int
{
    return test()->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $app->client_id, 'refresh_token' => $refresh])->status();
}

/** @return array{0: string, 1: string} the person and the session they are signed in with */
function soeSignedIn(): array
{
    $subject = app(Subjects::class)->create('me@acme.test', 'Me', 'a-strong-unbreached-passphrase');
    app(Subjects::class)->markEmailVerified($subject->id, 'me@acme.test');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.uniqid()));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);
    app(Sudo::class)->confirm();

    return [$subject->id, $session->id];
}

it('ends the grants of the sessions a person signs out, and keeps this one\'s (#26)', function (string $door): void {
    [$me, $current] = soeSignedIn();
    $thief = app(SessionManager::class)->start($me, null, ['pwd']);

    $stolen = soeApp('Stolen');
    $mine = soeApp('Mine');
    $stolenRefresh = app(RefreshTokens::class)->issue($stolen, $me, null, ['openid', 'offline_access'], sessionId: $thief->id);
    $stolenAccess = app(TokenIssuer::class)->issueForUser($stolen, $me, null, ['openid'])->token;
    $myRefresh = app(RefreshTokens::class)->issue($mine, $me, null, ['openid', 'offline_access'], sessionId: $current);

    match ($door) {
        'sign out every other session' => inertiaRequest(fn () => test()->from(route('account'))->post(route('account.sessions.revoke-others')))
            ->assertSessionHasNoErrors(),
        'change the password' => changeOwnPassword()->assertSessionHasNoErrors(),
    };

    expect(soeRefreshes($stolen, $stolenRefresh))->toBe(400)
        ->and(soeRefreshes($mine, $myRefresh))->toBe(200);
    $this->withToken($stolenAccess)->getJson('/oauth/userinfo')->assertUnauthorized();
})->with(['sign out every other session', 'change the password']);

it('ends the grants of every other session when a held password change completes (#26)', function (): void {
    [$me, $current] = soeSignedIn();
    app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $me,
        password: 'the-handed-over-passphrase',
        temporary: true,
        expiresAt: null,
        revoke: PasswordRevocationScope::Nothing,
    ));
    $thief = app(SessionManager::class)->start($me, null, ['pwd']);
    $stolen = soeApp('Stolen');
    $stolenRefresh = app(RefreshTokens::class)->issue($stolen, $me, null, ['openid', 'offline_access'], sessionId: $thief->id);

    test()->from(route('password.change'))->post(route('password.change.update'), [
        'password' => 'a-passphrase-only-they-know',
        'password_confirmation' => 'a-passphrase-only-they-know',
    ])->assertSessionHasNoErrors();

    expect(soeRefreshes($stolen, $stolenRefresh))->toBe(400)
        ->and(app(SessionManager::class)->active($current))->not->toBeNull();
});

it('withdraws every app grant when the password is reset (#26)', function (): void {
    $person = app(Subjects::class)->create('ada@acme.test', 'Ada', 'a-strong-unbreached-passphrase');
    $app = soeApp('Stolen');
    $session = app(SessionManager::class)->start($person->id, null, ['pwd']);
    $refresh = app(RefreshTokens::class)->issue($app, $person->id, null, ['openid', 'offline_access'], sessionId: $session->id);
    $orphan = app(RefreshTokens::class)->issue($app, $person->id, null, ['openid', 'offline_access']);
    $access = app(TokenIssuer::class)->issueForUser($app, $person->id, null, ['openid'])->token;

    $token = (string) app(PasswordReset::class)->request('ada@acme.test');

    test()->from(route('password.reset', $token))->post(route('password.update'), [
        'token' => $token,
        'password' => 'brand-new-password-5678',
        'password_confirmation' => 'brand-new-password-5678',
    ])->assertRedirect();

    expect(soeRefreshes($app, $refresh))->toBe(400)
        ->and(soeRefreshes($app, $orphan))->toBe(400);
    $this->withToken($access)->getJson('/oauth/userinfo')->assertUnauthorized();
});

it('withdraws nothing when the reset link is refused (#26)', function (): void {
    $person = app(Subjects::class)->create('ada@acme.test', 'Ada', 'a-strong-unbreached-passphrase');
    $app = soeApp('Mine');
    $refresh = app(RefreshTokens::class)->issue($app, $person->id, null, ['openid', 'offline_access']);

    test()->post(route('password.update'), [
        'token' => 'not-a-real-token',
        'password' => 'brand-new-password-5678',
        'password_confirmation' => 'brand-new-password-5678',
    ])->assertSessionHasErrors('password');

    expect(soeRefreshes($app, $refresh))->toBe(200);
});

it('ends the grants password sign-ins produced when SSO becomes mandatory, and keeps the SSO ones (#10)', function (string $scope): void {
    $person = app(Subjects::class)->create('ada@acme.test', 'Ada', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.uniqid()));
    app(Memberships::class)->add($org->id, $person->id, MembershipRole::Member);

    $byPassword = soeApp('Ledger');
    $bySso = soeApp('Authenticator');
    $passwordSession = app(SessionManager::class)->start($person->id, $org->id, ['pwd']);
    $passwordRefresh = app(RefreshTokens::class)->issue($byPassword, $person->id, $org->id, ['openid', 'offline_access'], amr: ['pwd'], sessionId: $passwordSession->id);
    $ssoRefresh = app(RefreshTokens::class)->issue($bySso, $person->id, $org->id, ['openid', 'offline_access'], amr: ['sso']);

    match ($scope) {
        'the organization' => app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(sso: SsoEnforcement::Required)),
        'the environment' => app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required)),
    };

    expect(soeRefreshes($byPassword, $passwordRefresh))->toBe(400)
        ->and(soeRefreshes($bySso, $ssoRefresh))->toBe(200);
})->with(['the organization', 'the environment']);

it('keeps a session-less grant (the CLI\'s device sign-in) when other sessions end', function (): void {
    [$me, $current] = soeSignedIn();
    app(SessionManager::class)->start($me, null, ['pwd']);
    $cli = soeApp('Cli');
    $deviceGrant = app(RefreshTokens::class)->issue($cli, $me, null, ['openid', 'offline_access']);

    app(EndOtherSessions::class)->handle($me, $current);

    expect(soeRefreshes($cli, $deviceGrant))->toBe(200);
});

/**
 * THE AUTHENTICATOR STAYS SIGNED IN UNLESS ITS PERSON'S ACCESS IS REALLY IN DOUBT.
 *
 * Its refresh family is DPoP-bound to the key of a device the person enrolled, and it cannot
 * sign in again by itself: revoking it signs the phone out of CIBA approvals and push
 * step-up. A password change (which proves the old password) and "sign out every other
 * session" spare it, even when the family is bound to one of the sessions being ended. A
 * password reset does not.
 *
 * @return array{0: string, 1: string} the person and the Authenticator's refresh token row id
 */
function soeAuthenticatorOn(string $me, string $sessionId): array
{
    Device::query()->create([
        'subject_id' => $me, 'install_id' => (string) Str::ulid(),
        'platform' => 'ios', 'name' => 'My phone', 'status' => 'active', 'dpop_jkt' => 'the-phone-key',
    ]);
    $raw = app(RefreshTokens::class)->issue(soeApp('Authenticator'), $me, null, ['openid', 'offline_access'], dpopJkt: 'the-phone-key', sessionId: $sessionId);

    return [$me, (string) RefreshToken::query()->where('token_hash', hash('sha256', $raw))->value('id')];
}

function soeRevoked(string $tokenId): bool
{
    return RefreshToken::query()->whereKey($tokenId)->value('revoked_at') !== null;
}

it('spares the Authenticator\'s device-bound grant when other sessions end', function (string $door): void {
    [$me] = soeSignedIn();
    $phoneSession = app(SessionManager::class)->start($me, null, ['pwd']);
    [, $authenticator] = soeAuthenticatorOn($me, $phoneSession->id);
    $other = soeApp('Ledger');
    $otherRefresh = app(RefreshTokens::class)->issue($other, $me, null, ['openid', 'offline_access'], sessionId: $phoneSession->id);

    match ($door) {
        'sign out every other session' => inertiaRequest(fn () => test()->from(route('account'))->post(route('account.sessions.revoke-others')))
            ->assertSessionHasNoErrors(),
        'change the password' => changeOwnPassword()->assertSessionHasNoErrors(),
    };

    expect(soeRevoked($authenticator))->toBeFalse()
        // An ordinary app of the same ended session is still signed out.
        ->and(soeRefreshes($other, $otherRefresh))->toBe(400)
        ->and(app(SessionManager::class)->active($phoneSession->id))->toBeNull();
})->with(['sign out every other session', 'change the password']);

it('withdraws the Authenticator\'s device-bound grant on a password reset', function (): void {
    $person = app(Subjects::class)->create('ada@acme.test', 'Ada', 'a-strong-unbreached-passphrase');
    $session = app(SessionManager::class)->start($person->id, null, ['pwd']);
    [, $authenticator] = soeAuthenticatorOn($person->id, $session->id);
    $token = (string) app(PasswordReset::class)->request('ada@acme.test');

    test()->from(route('password.reset', $token))->post(route('password.update'), [
        'token' => $token,
        'password' => 'brand-new-password-5678',
        'password_confirmation' => 'brand-new-password-5678',
    ])->assertRedirect();

    expect(soeRevoked($authenticator))->toBeTrue();
});

it('withdraws a device-bound grant once its device is no longer trusted', function (): void {
    [$me] = soeSignedIn();
    $phoneSession = app(SessionManager::class)->start($me, null, ['pwd']);
    [, $authenticator] = soeAuthenticatorOn($me, $phoneSession->id);
    Device::query()->where('subject_id', $me)->update(['status' => 'revoked']);

    changeOwnPassword()->assertSessionHasNoErrors();

    expect(soeRevoked($authenticator))->toBeTrue();
});
