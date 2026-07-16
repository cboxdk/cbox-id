<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
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
    app(Memberships::class)->add($org->id, $subject->id, 'owner');

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
