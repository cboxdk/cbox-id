<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\Enums\AttemptOutcome;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountMemberMfa;
use Cbox\Id\Platform\Models\Account;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Support\Collection;

/**
 * Signing in to the ACCOUNT plane must leave a trace on the account's own activity
 * chain.
 *
 * This is the highest-privilege surface in the product — it owns environments, API
 * keys and billing — and until now it recorded nothing at sign-in: the plane's
 * administration actions were audited (`account.member_invited`,
 * `account.environment_created`), but the act of getting IN was not, on any of the
 * five paths. An account takeover was therefore invisible in the very log an admin
 * would open to investigate one, and `last_login_at` (a single overwritten column)
 * was the only evidence a session had ever started.
 *
 * The assertion is deliberately made through {@see AccountAuth::establish()} rather
 * than through each door: establish() is the single place session state is created,
 * which is exactly why the record belongs there and cannot be forgotten by a new
 * sign-in method added later.
 */
if (! function_exists('provisionAuditableAccount')) {
    /**
     * @return array{member: AccountMember, account: Account}
     */
    function provisionAuditableAccount(string $email = 'owner@audit.example'): array
    {
        $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
            accountName: 'Audit Co',
            ownerEmail: $email,
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ));

        return ['member' => $result->member, 'account' => $result->account];
    }
}

function signInEntries(string $accountId): Collection
{
    return AuditEntry::query()
        ->where('scope', $accountId)
        ->where('action', 'account.signed_in')
        ->get();
}

it('records a sign-in on the account chain', function (): void {
    ['member' => $member, 'account' => $account] = provisionAuditableAccount();

    app(AccountAuth::class)->establish($member->id);

    $entry = signInEntries($account->id)->firstOrFail();

    // Attributed to the member, chained under the account — the same scope the
    // plane's other activity uses, so it lands in the one log an admin reads.
    expect($entry->actor_id)->toBe($member->id)
        ->and($entry->target_type)->toBe('account_member')
        ->and($entry->target_id)->toBe($member->id);
});

it('records a sign-in through the password door', function (): void {
    ['member' => $member, 'account' => $account] = provisionAuditableAccount('pwd@audit.example');

    $outcome = app(AccountAuth::class)->attempt(request(), 'pwd@audit.example', 'a-strong-unbreached-passphrase');

    expect($outcome)->toBe(AttemptOutcome::Ok)
        ->and(signInEntries($account->id))->toHaveCount(1);
});

it('records nothing while a second factor is still outstanding', function (): void {
    ['member' => $member, 'account' => $account] = provisionAuditableAccount('mfa@audit.example');

    // A confirmed TOTP holds the password door at the challenge — no session is
    // established, so nothing may be recorded as a sign-in yet.
    $mfa = app(AccountMemberMfa::class);
    $enroll = $mfa->enrollTotp($member->id, $member->email);
    $mfa->confirmTotp($member->id, app(TotpAuthenticator::class)->codeAt($enroll->secret, time()));

    $outcome = app(AccountAuth::class)->attempt(request(), 'mfa@audit.example', 'a-strong-unbreached-passphrase');

    expect($outcome)->toBe(AttemptOutcome::Mfa)
        ->and(signInEntries($account->id))->toHaveCount(0);
});

it('records one entry per session, not one per account', function (): void {
    ['member' => $member, 'account' => $account] = provisionAuditableAccount('repeat@audit.example');

    $auth = app(AccountAuth::class);
    $auth->establish($member->id);
    $auth->establish($member->id);

    // Two sessions, two entries — the log is a history, not a last-seen column.
    expect(signInEntries($account->id))->toHaveCount(2);
});

it('never lets a sign-in failure break the sign-in', function (): void {
    ['member' => $member] = provisionAuditableAccount('resilient@audit.example');

    // Audit is an observation, not a gate: an audit backend that is down must not
    // lock every account member out of their own console.
    $this->mock(AuditLog::class)
        ->shouldReceive('record')->andThrow(new RuntimeException('audit down'));

    app(AccountAuth::class)->establish($member->id);

    expect(session()->get(AccountAuth::SESSION_KEY))->toBe($member->id);
});
