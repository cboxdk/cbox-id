<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\IdentityLink;
use Cbox\Id\Identity\Models\MfaFactor;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\RefreshToken;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Contracts\UserApiTokens;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\TokenScope;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

/**
 * A SIGN-IN LINK PROVES THE MAILBOX, SO IT DECIDES WHO HOLDS AN UNVERIFIED ACCOUNT.
 *
 * Until the address is confirmed, nothing says the password, factors, links, sessions and
 * keys on an account were set by the person who receives its mail. When a sign-in link
 * proves that, the address becomes verified and everything attached before it is removed,
 * so the mailbox holder ends up the only one who can get in.
 */
beforeEach(fn () => installedDeployment());

/**
 * An account with every kind of way in, set up before the address was confirmed.
 *
 * @return array{subjectId: string, sessionId: string, apiToken: string}
 */
function accountWithCredentials(bool $verified): array
{
    $subjects = app(Subjects::class);
    $subject = $subjects->create('owner@acme.test', 'Owner', 'credential-set-before-proof-1');

    if ($verified) {
        $subjects->markEmailVerified($subject->id, 'owner@acme.test');
    }

    $session = app(SessionManager::class)->start($subject->id, null, ['pwd']);

    $factor = app(Mfa::class)->enrollTotp($subject->id, 'owner@acme.test');
    confirmTotpForTest($subject->id, $factor->secret);

    WebAuthnCredential::query()->create([
        'user_id' => $subject->id, 'credential_id' => 'pre-proof-credential',
        'public_key' => 'unused-in-this-flow', 'sign_count' => 0,
    ]);

    $subjects->link($subject->id, new FederatedPrincipal('github', 'pre-proof-github-id'));

    $org = app(Organizations::class)->create(new NewOrganization('Owner Org', 'owner-org'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $token = app(UserApiTokens::class)->issue($org->id, $subject->id, 'cli', TokenScope::Read);

    $client = app(ClientRegistry::class)->register(new NewClient(
        'Downstream', type: ClientType::Public, redirectUris: ['https://downstream.example.test/cb'],
        grantTypes: ['authorization_code', 'refresh_token'], scopes: ['openid', 'offline_access'], firstParty: true, organizationId: $org->id,
    ))->client;
    app(RefreshTokens::class)->issue($client, $subject->id, $org->id, ['openid', 'offline_access']);

    return ['subjectId' => $subject->id, 'sessionId' => $session->id, 'apiToken' => $token->plaintext];
}

it('verifies an unverified account and removes every credential set before the mailbox was proven', function (): void {
    ['subjectId' => $id, 'sessionId' => $earlierSession, 'apiToken' => $apiToken] = accountWithCredentials(verified: false);

    $link = app(MagicLink::class)->request('owner@acme.test');
    $this->post(route('magic.redeem.store', $link))->assertRedirect(route('dashboard'));

    $subjects = app(Subjects::class);
    $sessions = app(SessionManager::class);

    expect($subjects->find($id)?->emailVerified)->toBeTrue()
        ->and($subjects->verifyPassword($id, 'credential-set-before-proof-1'))->toBeFalse()
        ->and($sessions->active($earlierSession))->toBeNull()
        ->and(MfaFactor::query()->where('user_id', $id)->exists())->toBeFalse()
        ->and(WebAuthnCredential::query()->where('user_id', $id)->exists())->toBeFalse()
        ->and(IdentityLink::query()->where('user_id', $id)->exists())->toBeFalse()
        ->and(app(UserApiTokens::class)->resolve($apiToken))->toBeNull()
        ->and(RefreshToken::query()->where('user_id', $id)->whereNull('revoked_at')->exists())->toBeFalse();

    // The person who proved the mailbox is the one signed in.
    $current = $sessions->active(session(PlatformAuth::SESSION_KEY));
    expect($current?->user_id)->toBe($id)
        ->and($current?->amr)->toBe(['magic_link']);
});

it('leaves a verified account exactly as it was', function (): void {
    ['subjectId' => $id, 'sessionId' => $earlierSession, 'apiToken' => $apiToken] = accountWithCredentials(verified: true);

    $link = app(MagicLink::class)->request('owner@acme.test');
    // The enrolled factor still stands in the way: a sign-in link is not a second factor.
    $this->post(route('magic.redeem.store', $link))->assertRedirect(route('mfa'));

    expect(app(Subjects::class)->verifyPassword($id, 'credential-set-before-proof-1'))->toBeTrue()
        ->and(app(SessionManager::class)->active($earlierSession))->not->toBeNull()
        ->and(MfaFactor::query()->where('user_id', $id)->exists())->toBeTrue()
        ->and(WebAuthnCredential::query()->where('user_id', $id)->exists())->toBeTrue()
        ->and(IdentityLink::query()->where('user_id', $id)->exists())->toBeTrue()
        ->and(app(UserApiTokens::class)->resolve($apiToken))->not->toBeNull()
        ->and(RefreshToken::query()->where('user_id', $id)->whereNull('revoked_at')->exists())->toBeTrue();
});

it('marks the address of an account the link itself created as verified', function (): void {
    $link = app(MagicLink::class)->request('newcomer@acme.test');
    $this->post(route('magic.redeem.store', $link))->assertRedirect(route('dashboard'));

    expect(app(Subjects::class)->findByEmail('newcomer@acme.test')?->emailVerified)->toBeTrue();
});
