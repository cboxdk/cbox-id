<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\TotpDevices;
use Cbox\Id\Identity\Models\MfaFactor;
use Cbox\Id\Identity\Models\MfaRecoveryCode;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * One instant for every code in this file, mid-step (15 s into a 30 s window). Codes used to
 * be taken at `time()` and `time() - 30` and checked against the server's own `time()`, so a
 * run that crossed a step boundary between the two moved the confirmed step out of the
 * ±1 window and failed. The authenticator the app verifies with reads the same instant.
 */
const NAMED_TOTP_NOW = 1_800_000_015;

beforeEach(function (): void {
    app()->instance(TotpAuthenticator::class, new class extends TotpAuthenticator
    {
        public function matchStep(string $base32Secret, string $code, ?int $timestamp = null, int $window = 1): ?int
        {
            return parent::matchStep($base32Secret, $code, $timestamp ?? NAMED_TOTP_NOW, $window);
        }
    });
    app()->forgetInstance(Mfa::class);
    app()->forgetInstance(TotpDevices::class);
});

function namedTotpOwner(): string
{
    [$subject, $org] = accountWithOrg('named-apps@example.test');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);
    app(Sudo::class)->confirm();

    return $subject->id;
}

/** @return array{id: string, secret: string} */
function namedTotpEnrol(string $name): array
{
    test()->get(route('account'))->assertOk(); // consume the previous one-time flash, as the browser does
    app(Sudo::class)->confirm();
    inertiaRequest(fn () => test()->from(route('account'))->post(route('account.mfa.enrol'), ['name' => $name]))
        ->assertSessionHasNoErrors();

    return ['id' => flashed('mfaFactorId'), 'secret' => flashed('mfaSecret')];
}

function namedTotpConfirm(array $app): void
{
    app(Sudo::class)->confirm();
    inertiaRequest(fn () => test()->from(route('account'))->post(route('account.mfa.confirm'), [
        'factor_id' => $app['id'],
        'code' => app(TotpAuthenticator::class)->codeAt($app['secret'], NAMED_TOTP_NOW - 30),
    ]))->assertSessionHasNoErrors();
}

it('adds three separately named apps without replacing keys or recovery codes', function (): void {
    $userId = namedTotpOwner();
    $first = namedTotpEnrol('1Password');
    namedTotpConfirm($first);
    $recovery = flashed('recoveryCodes');
    $hashes = MfaRecoveryCode::query()->where('user_id', $userId)->pluck('code_hash')->all();
    $second = namedTotpEnrol('Cbox ID on iPhone');
    expect(app(Mfa::class)->hasConfirmedTotp($userId))->toBeTrue();
    namedTotpConfirm($second);
    expect(flashed('recoveryCodes'))->toBeNull();
    $third = namedTotpEnrol('Backup authenticator');
    namedTotpConfirm($third);

    expect(MfaRecoveryCode::query()->where('user_id', $userId)->pluck('code_hash')->all())->toBe($hashes)
        ->and(app(Mfa::class)->verifyRecoveryCode($userId, $recovery[0]))->toBeTrue();
    $props = accountSecurity()['twoFactor'];
    expect(array_column($props['devices'], 'name'))->toBe(['1Password', 'Cbox ID on iPhone', 'Backup authenticator'])
        ->and(json_encode($props))->not->toContain($first['secret'])
        ->and(json_encode($props))->not->toContain($second['secret'])
        ->and(json_encode($props))->not->toContain($third['secret']);
    foreach ([$first, $second, $third] as $app) {
        expect(app(Mfa::class)->verifyTotp($userId, app(TotpAuthenticator::class)->codeAt($app['secret'], NAMED_TOTP_NOW)))->toBeTrue();
    }
});

it('renames and removes one app while retaining the other and guarding the last', function (): void {
    $userId = namedTotpOwner();
    $first = namedTotpEnrol('First');
    namedTotpConfirm($first);
    $second = namedTotpEnrol('Second');
    namedTotpConfirm($second);
    inertiaRequest(fn () => test()->from(route('account'))->patch(route('account.mfa.totp.update', $second['id']), ['name' => 'Spare phone']))
        ->assertSessionHasNoErrors();
    expect(MfaFactor::query()->findOrFail($second['id'])->name)->toBe('Spare phone');

    app(Sudo::class)->confirm();
    inertiaRequest(fn () => test()->from(route('account'))->delete(route('account.mfa.totp.destroy', $first['id'])))
        ->assertSessionHasNoErrors();
    expect(app(Mfa::class)->verifyTotp($userId, app(TotpAuthenticator::class)->codeAt($first['secret'], NAMED_TOTP_NOW)))->toBeFalse()
        ->and(app(Mfa::class)->verifyTotp($userId, app(TotpAuthenticator::class)->codeAt($second['secret'], NAMED_TOTP_NOW)))->toBeTrue();
    inertiaRequest(fn () => test()->from(route('account'))->delete(route('account.mfa.totp.destroy', $second['id'])))
        ->assertSessionHasErrors('form');
    expect(app(TotpDevices::class)->devices($userId))->toHaveCount(1);
});

it('cancels an unfinished setup and cannot confirm its discarded key', function (): void {
    $userId = namedTotpOwner();
    $app = namedTotpEnrol('Cancelled');
    inertiaRequest(fn () => test()->from(route('account'))->delete(route('account.mfa.totp.destroy', $app['id'])))
        ->assertSessionHasNoErrors();
    expect(MfaFactor::query()->find($app['id']))->toBeNull()
        ->and(session()->has('mfa_pending_factor_id'))->toBeFalse();
    confirmMfaEnrolment(app(TotpAuthenticator::class)->codeAt($app['secret'], NAMED_TOTP_NOW))->assertSessionHasErrors('code');
    expect(app(Mfa::class)->hasConfirmedTotp($userId))->toBeFalse();
});

it('does not reveal or mutate another persons apps through any route', function (): void {
    namedTotpOwner();
    $foreign = app(TotpDevices::class)->enrollDevice('somebody-else', 'other@example.test', 'Private');
    $code = app(TotpAuthenticator::class)->codeAt($foreign->secret, NAMED_TOTP_NOW);
    inertiaRequest(fn () => test()->from(route('account'))->post(route('account.mfa.confirm'), ['code' => $code, 'factor_id' => $foreign->id]))
        ->assertSessionHasErrors('code');
    test()->patch(route('account.mfa.totp.update', $foreign->id), ['name' => 'Hijacked'])->assertNotFound();
    test()->delete(route('account.mfa.totp.destroy', $foreign->id))->assertNotFound();
    expect(MfaFactor::query()->findOrFail($foreign->id)->confirmed_at)->toBeNull();
});

it('requires fresh authentication to add or remove an app', function (): void {
    namedTotpOwner();
    $app = namedTotpEnrol('Phone');
    namedTotpConfirm($app); // rotates the session, expiring sudo
    assertAskedToStepUp(inertiaRequest(fn () => test()->delete(route('account.mfa.totp.destroy', $app['id']))));
    assertAskedToStepUp(beginMfaEnrolment());
    expect(MfaFactor::query()->find($app['id']))->not->toBeNull();
});

it('keeps confirmation failures bounded across apps', function (): void {
    namedTotpOwner();
    $first = namedTotpEnrol('Phone');
    $second = namedTotpEnrol('Backup');
    for ($i = 0; $i < 5; $i++) {
        inertiaRequest(fn () => test()->post(route('account.mfa.confirm'), ['code' => '999999', 'factor_id' => $first['id']]))
            ->assertSessionHasErrors('code');
    }
    inertiaRequest(fn () => test()->post(route('account.mfa.confirm'), [
        'code' => app(TotpAuthenticator::class)->codeAt($second['secret'], NAMED_TOTP_NOW), 'factor_id' => $second['id'],
    ]))->assertSessionHasErrors('code');
    expect(MfaFactor::query()->whereNotNull('confirmed_at')->count())->toBe(0);
});

it('retains an existing recovery set when adding the first TOTP app beside SMS', function (): void {
    $userId = namedTotpOwner();
    $recovery = app(Mfa::class)->generateRecoveryCodes($userId);
    namedTotpConfirm(namedTotpEnrol('Phone'));
    expect(flashed('recoveryCodes'))->toBeNull()
        ->and(app(Mfa::class)->verifyRecoveryCode($userId, $recovery[0]))->toBeTrue();
});
