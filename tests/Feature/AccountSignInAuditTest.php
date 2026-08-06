<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\Enums\AttemptOutcome;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
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
     * @return array{member: Membership, account: Account}
     */
    function provisionAuditableAccount(string $email = 'owner@audit.example'): array
    {
        // The platform root FIRST. An account provisioned without one is in the
        // first-install bootstrap window: its members have no subject, and a member
        // with no subject has nothing to sign in.
        platformRootEnvironment();

        $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
            organizationName: 'Audit Co',
            ownerEmail: $email,
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ));

        return ['member' => $result->membership, 'subjectId' => $result->owner->id, 'organization' => $result->account];
    }
}

function signInEntries(string $accountId): Collection
{
    // IN THE PLATFORM ROOT, the way the console reads it: an audit entry is
    // environment-owned, and the account chain lives in the root because that is the
    // environment the account host resolves to. Read with no environment selected this
    // returns the deny-by-default scope's empty set for every case, which is the same
    // answer "nothing was recorded" gives — so the helper pins the scope rather than
    // leaving each test to pass for the wrong reason.
    return app(PlatformRoot::class)->run(
        fn (): Collection => AuditEntry::query()
            ->where('scope', $accountId)
            ->where('action', 'account.signed_in')
            ->get(),
    );
}

it('records a sign-in on the account chain', function (): void {
    ['member' => $member, 'organization' => $account] = provisionAuditableAccount();

    app(AccountAuth::class)->establish($member->id);

    $entry = signInEntries($account->id)->firstOrFail();

    // Attributed to the member, chained under the account — the same scope the
    // plane's other activity uses, so it lands in the one log an admin reads.
    expect($entry->actor_id)->toBe($member->id)
        ->and($entry->target_type)->toBe('account_member')
        ->and($entry->target_id)->toBe($member->id);
});

it('records a sign-in through the password door', function (): void {
    ['member' => $member, 'organization' => $account] = provisionAuditableAccount('pwd@audit.example');

    $outcome = signInAtLogin('pwd@audit.example', 'a-strong-unbreached-passphrase');

    expect($outcome)->toBe(AttemptOutcome::Ok)
        ->and(signInEntries($account->id))->toHaveCount(1);
});

it('records nothing when the door holds the person back', function (): void {
    ['organization' => $account] = provisionAuditableAccount('held@audit.example');

    // A door that refuses establishes no session, so there is no sign-in to record — the
    // log is of sessions, not of attempts, and an entry here would report a person as
    // having got in when they did not.
    //
    // Asserted through the SSO MANDATE. It used to be asserted through an account-plane
    // TOTP, which no longer exists: that factor was unenforceable once the one sign-in
    // served the platform root, so it was removed rather than left looking like
    // protection. The property it was demonstrating is unchanged and is worth keeping, so
    // it moved to a hold that is still real.
    app(PlatformRoot::class)->run(
        fn () => app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required)),
    );

    $outcome = signInAtLogin('held@audit.example', 'a-strong-unbreached-passphrase');

    expect($outcome)->toBe(AttemptOutcome::SsoRequired)
        ->and(signInEntries($account->id))->toHaveCount(0);
});

it('records one entry per session, not one per account', function (): void {
    ['member' => $member, 'organization' => $account] = provisionAuditableAccount('repeat@audit.example');

    // Through the door people use, twice. It used to sign in twice through
    // `AccountAuth::establish()`, which two flows still reach — but the property being
    // claimed is about the log being a history rather than a last-seen column, and the
    // path that produces almost every entry is the one that has to demonstrate it.
    signInAtLogin('repeat@audit.example', 'a-strong-unbreached-passphrase');
    signInAtLogin('repeat@audit.example', 'a-strong-unbreached-passphrase');

    expect(signInEntries($account->id))->toHaveCount(2);
});

it('never lets a sign-in failure break the sign-in', function (): void {
    ['member' => $member] = provisionAuditableAccount('resilient@audit.example');

    // Audit is an observation, not a gate: an audit backend that is down must not
    // lock every account member out of their own console.
    $this->mock(AuditLog::class)
        ->shouldReceive('record')->andThrow(new RuntimeException('audit down'));

    // Establishing still SUCCEEDS — the audit append is what failed.
    expect(app(AccountAuth::class)->establish($member->id))->toBeTrue()
        ->and(app(AccountAuth::class)->current()?->id)->toBe($member->id);
});
