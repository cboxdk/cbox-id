<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Crypto\Contracts\TokenSigner;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

/*
|--------------------------------------------------------------------------
| AN APP'S SIGN-OUT ENDS THE SESSION ROW, ON A REAL REQUEST
|--------------------------------------------------------------------------
|
| `/oauth/logout` asks two questions of the app: which session this browser holds and who
| holds it. Both were answered from CurrentUser, which only the console's authentication
| middleware fills in, and that middleware does not run on this route. So on a real request
| both answers were "nobody": the cookie was dropped and the browser looked signed out, the
| session row stayed active, and no app it had signed the person in to was told
| (Back-Channel Logout never went out). The tests that cover the endpoint set CurrentUser by
| hand, which is why they were green. These send only what a browser sends: its cookie.
*/

beforeEach(fn () => installedDeployment());

/** @return array{0: string, 1: Session, 2: Session} the subject, this browser's session, another device's */
function rpLogoutPerson(string $email): array
{
    $subject = app(Subjects::class)->create($email, 'Signing Out', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.substr(md5($email), 0, 8)));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $sessions = app(SessionManager::class);
    $thisBrowser = $sessions->start($subject->id, $org->id, ['pwd']);
    $phone = $sessions->start($subject->id, $org->id, ['pwd']);

    // What the browser holds: its session cookie. Nothing else.
    session([PlatformAuth::SESSION_KEY => $thisBrowser->id]);

    return [$subject->id, $thisBrowser, $phone];
}

function rpLogoutRevoked(Session $session): bool
{
    return Session::query()->whereKey($session->id)->value('revoked_at') !== null;
}

it('ends this browser\'s session row on a logout with no hint, and nothing else', function (): void {
    [, $thisBrowser, $phone] = rpLogoutPerson('nohint@rp-logout.test');

    $this->get('/oauth/logout')->assertRedirectContains('/signed-out');

    expect(rpLogoutRevoked($thisBrowser))->toBeTrue()
        ->and(rpLogoutRevoked($phone))->toBeFalse();
});

it('ends every session the person holds on a logout with a verified hint that names them', function (): void {
    [$subjectId, $thisBrowser, $phone] = rpLogoutPerson('hint@rp-logout.test');
    $hint = app(TokenSigner::class)->sign(['sub' => $subjectId, 'aud' => 'some-client', 'iat' => time(), 'exp' => time() + 3600]);

    $this->post('/oauth/logout', ['id_token_hint' => $hint])->assertRedirectContains('/signed-out');

    expect(rpLogoutRevoked($thisBrowser))->toBeTrue()
        ->and(rpLogoutRevoked($phone))->toBeTrue();
});

it('leaves the person\'s other devices alone when the hint names somebody else', function (): void {
    [, $thisBrowser, $phone] = rpLogoutPerson('mine@rp-logout.test');
    $other = app(Subjects::class)->create('other@rp-logout.test', 'Other', 'a-strong-unbreached-passphrase');
    $hint = app(TokenSigner::class)->sign(['sub' => $other->id, 'aud' => 'some-client', 'iat' => time(), 'exp' => time() + 3600]);

    $this->get('/oauth/logout?'.http_build_query(['id_token_hint' => $hint]))->assertRedirectContains('/signed-out');

    expect(rpLogoutRevoked($thisBrowser))->toBeTrue()
        ->and(rpLogoutRevoked($phone))->toBeFalse();
})->group('security');

it('ends nothing for a browser whose session was already revoked', function (): void {
    [$subjectId, $thisBrowser, $phone] = rpLogoutPerson('stale@rp-logout.test');
    app(SessionManager::class)->revoke($thisBrowser->id);
    $hint = app(TokenSigner::class)->sign(['sub' => $subjectId, 'aud' => 'some-client', 'iat' => time(), 'exp' => time() + 3600]);

    // A revoked session proves nobody: the hint alone may not end the person's other devices.
    $this->get('/oauth/logout?'.http_build_query(['id_token_hint' => $hint]))->assertRedirectContains('/signed-out');

    expect(rpLogoutRevoked($phone))->toBeFalse();
})->group('security');
