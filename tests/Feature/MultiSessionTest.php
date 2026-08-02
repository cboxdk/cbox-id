<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * @return array{0: string, 1: string} [subjectId, orgId]
 */
function makeAccount(string $email): array
{
    $subject = app(Subjects::class)->create($email, 'User '.$email, 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Org', 'org-'.substr(md5($email), 0, 6)));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    return [$subject->id, $org->id];
}

function platformAuth(): PlatformAuth
{
    return app(PlatformAuth::class);
}

it('holds multiple accounts signed in at once, newest active', function (): void {
    [$a] = makeAccount('a@test.dev');
    [$b] = makeAccount('b@test.dev');

    platformAuth()->establish(request(), $a, ['pwd']);
    platformAuth()->establish(request(), $b, ['pwd']);

    $accounts = platformAuth()->accounts();

    expect(array_column($accounts, 'subject_id'))->toContain($a, $b)
        ->and(collect($accounts)->firstWhere('subject_id', $b)['active'])->toBeTrue()
        ->and(session()->get(PlatformAuth::ACTIVE_KEY))->toBe($b);
});

it('switches the active account without re-authenticating', function (): void {
    [$a] = makeAccount('a2@test.dev');
    [$b] = makeAccount('b2@test.dev');

    platformAuth()->establish(request(), $a, ['pwd']);
    $aSession = session()->get(PlatformAuth::SESSION_KEY);
    platformAuth()->establish(request(), $b, ['pwd']);

    expect(platformAuth()->switchTo(request(), $a))->toBeTrue()
        ->and(session()->get(PlatformAuth::ACTIVE_KEY))->toBe($a)
        ->and(session()->get(PlatformAuth::SESSION_KEY))->toBe($aSession);
});

it('refuses to switch to an account that is not signed in', function (): void {
    [$a] = makeAccount('a3@test.dev');
    platformAuth()->establish(request(), $a, ['pwd']);

    expect(platformAuth()->switchTo(request(), 'not-signed-in'))->toBeFalse()
        ->and(session()->get(PlatformAuth::ACTIVE_KEY))->toBe($a);
});

it('logging out one account activates the next and stays signed in', function (): void {
    [$a] = makeAccount('a4@test.dev');
    [$b] = makeAccount('b4@test.dev');
    platformAuth()->establish(request(), $a, ['pwd']);
    platformAuth()->establish(request(), $b, ['pwd']); // b is active

    platformAuth()->logout(request()); // logs out b, activates a

    expect(session()->get(PlatformAuth::ACTIVE_KEY))->toBe($a)
        ->and(session()->has(PlatformAuth::SESSION_KEY))->toBeTrue()
        ->and(array_column(platformAuth()->accounts(), 'subject_id'))->toBe([$a]);
});

it('logging out the last account tears the browser session down', function (): void {
    [$a] = makeAccount('a5@test.dev');
    platformAuth()->establish(request(), $a, ['pwd']);

    platformAuth()->logout(request());

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse()
        ->and(platformAuth()->accounts())->toBe([]);
});

it('logs out of every account with logoutAll', function (): void {
    [$a] = makeAccount('a6@test.dev');
    [$b] = makeAccount('b6@test.dev');
    platformAuth()->establish(request(), $a, ['pwd']);
    platformAuth()->establish(request(), $b, ['pwd']);

    platformAuth()->logoutAll(request());

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse()
        ->and(platformAuth()->accounts())->toBe([]);
});

/**
 * A step-up window belongs to the identity that cleared it, and to no other.
 *
 * `Sudo` and `WorkspaceSudo` store a bare timestamp under one global session key — no
 * subject, no member, no session id — and `regenerate()` rotates the session id while
 * KEEPING the data. So any transition that hands the session to a different person and
 * only regenerates has, mechanically, handed over the elevation too.
 *
 * That is not theoretical. The attacker supplies the password of an account THEY own,
 * clears sudo with it, then moves the session to a victim whose session arrived through
 * a door that never asks for a password — a redeemed magic link, an SSO assertion. Every
 * sudo-gated route then accepts them: passkey enrolment, provider linking, vault reveal,
 * and on the account plane the minting of account and environment API keys. A transient,
 * revocable session becomes durable credentials that outlive the victim revoking the
 * original vector and resetting their password — the exact persistence the step-up
 * exists to prevent.
 */
it('ends the step-up window when the active account changes', function (): void {
    [$a] = makeAccount('sudo-switch-a@test.dev');
    [$b] = makeAccount('sudo-switch-b@test.dev');

    platformAuth()->establish(request(), $a, ['pwd']);
    platformAuth()->establish(request(), $b, ['pwd']);

    // Cleared as B, with B's own password.
    app(Sudo::class)->confirm();
    expect(app(Sudo::class)->confirmed())->toBeTrue();

    platformAuth()->switchTo(request(), $a);

    expect(app(Sudo::class)->confirmed())
        ->toBeFalse('an elevation confirmed as one account survived into another');
});

it('ends the step-up window when signing out promotes the next account', function (): void {
    [$a] = makeAccount('sudo-logout-a@test.dev');
    [$b] = makeAccount('sudo-logout-b@test.dev');

    platformAuth()->establish(request(), $a, ['pwd']);
    platformAuth()->establish(request(), $b, ['pwd']);

    app(Sudo::class)->confirm();

    // Signing out of B promotes A — a different person, same browser session.
    platformAuth()->logout(request());

    expect(app(Sudo::class)->confirmed())
        ->toBeFalse('signing out of one account left the next one elevated');
});
