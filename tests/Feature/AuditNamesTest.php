<?php

declare(strict_types=1);

use App\Platform\AuditNames;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * An audit row's value is the sentence it tells. Rendered raw it said "organization ·
 * member added · user 01kywrnrvv…", withholding the one fact the reader came for.
 */
function auditNamesOrg(): array
{
    $admin = app(Subjects::class)->create('ada@acme.test', 'Ada Lovelace', 'super-secret-1234');
    $member = app(Subjects::class)->create('grace@acme.test', 'Grace Hopper', 'super-secret-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-audit-names'));
    app(Memberships::class)->add($org->id, $admin->id, MembershipRole::Owner);

    $session = app(SessionManager::class)->start($admin->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($admin, $session, $org, MembershipRole::Owner);

    return [$org, $admin, $member];
}

/** Recorded through the real audit log, so the row is shaped exactly as production's. */
function recordAudit(
    string $orgId,
    string $action,
    ActorType $actorType = ActorType::System,
    ?string $actorId = null,
    ?string $targetType = null,
    ?string $targetId = null,
): AuditEntry {
    return app(AuditLog::class)->record(new AuditEvent(
        action: $action,
        actorType: $actorType,
        actorId: $actorId,
        organizationId: $orgId,
        targetType: $targetType,
        targetId: $targetId,
    ));
}

it('names the person an entry is about, and the one who did it', function (): void {
    [$org, $admin, $member] = auditNamesOrg();

    $entry = recordAudit($org->id, 'organization.member_added', ActorType::User, $admin->id, 'user', $member->id);

    $names = app(AuditNames::class)->for(collect([$entry]));

    expect($names[$admin->id])->toBe('Ada Lovelace')
        ->and($names[$member->id])->toBe('Grace Hopper');
});

it('falls back to the email when a person has no name', function (): void {
    [$org, $admin] = auditNamesOrg();
    $nameless = app(Subjects::class)->create('nameless@acme.test', null, 'super-secret-1234');

    $entry = recordAudit($org->id, 'user.created', targetType: 'user', targetId: $nameless->id);

    expect(app(AuditNames::class)->for(collect([$entry]))[$nameless->id])->toBe('nameless@acme.test');
});

it('leaves an id it cannot resolve absent rather than guessing', function (): void {
    [$org] = auditNamesOrg();

    $entry = recordAudit($org->id, 'session.started', targetType: 'session', targetId: '01kywyjz260000000000000000');

    // The view falls back to the truncated id, which is still better than a wrong name.
    expect(app(AuditNames::class)->for(collect([$entry])))->toBe([]);
});

it('resolves a whole page in a fixed number of queries, not one per row', function (): void {
    [$org, $admin, $member] = auditNamesOrg();

    $entries = collect(range(1, 25))->map(
        fn (): object => recordAudit($org->id, 'organization.member_added', ActorType::User, $admin->id, 'user', $member->id),
    );

    DB::enableQueryLog();
    app(AuditNames::class)->for($entries);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // One batch per id type that appears — never one per row.
    expect($queries)->toBeLessThanOrEqual(3);
});

it('shows the names on the activity log itself, not only on the dashboard', function (): void {
    [$org, $admin, $member] = auditNamesOrg();

    recordAudit($org->id, 'organization.member_added', ActorType::User, $admin->id, 'user', $member->id);

    $this->get(route('audit'))
        ->assertOk()
        ->assertSee('Ada Lovelace')
        ->assertSee('Grace Hopper');
});
