<?php

declare(strict_types=1);

use App\Platform\Impersonation;
use App\Platform\ImpersonationAwareAuditLog;
use App\Platform\OperatorAuth;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Testing\FakeAuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Models\PlatformOperator;

/** An operator whose console reads are pinned to the default test plane. */
function impersonationOperator(string $email = 'imp-op@platform.test'): PlatformOperator
{
    return app(PlatformOperators::class)->create($email, 'a-strong-operator-pass', 'Op');
}

/**
 * A member account inside the default test plane. Defaults to a REGULAR member —
 * owners and admins are not impersonable (an operator inheriting their elevated
 * surface is exactly the risk we close), so the happy-path helper must be a member.
 */
function impersonationMember(string $email = 'member@acme.test', string $role = 'member'): array
{
    $org = app(Organizations::class)->create(new NewOrganization('Acme Inc', 'acme-'.substr(md5($email), 0, 6)));
    $subject = app(Subjects::class)->create($email, 'Member One', 'supersecret123');
    app(Memberships::class)->add($org->id, $subject->id, $role);

    return [$org, $subject];
}

/** A valid PAM justification for the start POST. */
const IMPERSONATION_REASON = 'Investigating support ticket #4271';

it('lets an operator step into a member and become purely the subject, audited', function (): void {
    $audit = new FakeAuditLog;
    app()->instance(AuditLog::class, $audit);

    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->withSession([OperatorAuth::SESSION_KEY => $op->id])
        ->post(route('operator.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON])
        ->assertRedirect(route('dashboard'));

    // The browser is now the subject: dashboard loads, and the impersonation marker
    // is present while the operator key is gone.
    expect(session(Impersonation::SESSION_KEY))->not->toBeNull()
        ->and(session(OperatorAuth::SESSION_KEY))->toBeNull()
        ->and(session(PlatformAuth::SESSION_KEY))->not->toBeNull();

    // The subject session authenticated with an `impersonation` amr — never a login.
    $sessionId = session(PlatformAuth::SESSION_KEY);
    $row = app(SessionManager::class)->active($sessionId);
    expect($row)->not->toBeNull()
        ->and($row->user_id)->toBe($member->id)
        ->and($row->amr)->toContain('impersonation');

    // Operator routes are unreachable while impersonating (no operator key).
    $this->get(route('operator.organizations'))->assertRedirect(route('operator.login'));

    // The dashboard loads as the subject and shows the impersonation banner.
    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('member@acme.test')
        ->assertSee('impersonating', false);

    $audit->assertRecorded(
        'operator.impersonation_started',
        fn (AuditEvent $e): bool => $e->actorType === ActorType::Operator
            && $e->actorId === $op->id
            && $e->targetType === 'user'
            && $e->targetId === $member->id
            && $e->organizationId === $org->id,
    );
});

it('refuses to impersonate a user who is not a member of a viewable org (403)', function (): void {
    $op = impersonationOperator();
    [$org] = impersonationMember();
    $stranger = app(Subjects::class)->create('stranger@nowhere.test', 'Stranger', 'supersecret123');

    // Real org, real user, but no membership between them → 403.
    $this->withSession([OperatorAuth::SESSION_KEY => $op->id])
        ->post(route('operator.impersonate', $stranger->id), ['organization' => $org->id])
        ->assertForbidden();

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
});

it('refuses to impersonate a member of an org in another plane (403)', function (): void {
    $op = impersonationOperator();

    // Pin the operator to the default plane explicitly (its Environment row must
    // exist for the slug lookup in SetEnvironment to resolve it).
    Environment::query()->create(['name' => 'Default', 'slug' => 'env_test', 'status' => 'active']);

    // Org + member live entirely in ANOTHER plane. Memberships::of is plane-scoped
    // to the operator's pinned plane → resolves to null → 403.
    $env = Environment::query()->create(['name' => 'Other', 'slug' => 'other-env', 'status' => 'active']);
    [$foreignOrgId, $foreignUserId] = app(EnvironmentContext::class)->runAs($env, function (): array {
        $org = app(Organizations::class)->create(new NewOrganization('Foreign', 'foreign'));
        $subject = app(Subjects::class)->create('foreign@acme.test', 'F', 'supersecret123');
        app(Memberships::class)->add($org->id, $subject->id, 'owner');

        return [$org->id, $subject->id];
    });

    $this->withSession([OperatorAuth::SESSION_KEY => $op->id, OperatorAuth::ENV_KEY => 'env_test'])
        ->post(route('operator.impersonate', $foreignUserId), ['organization' => $foreignOrgId])
        ->assertForbidden();

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
});

it('refuses a non-operator hitting the start route', function (): void {
    [$org, $member] = impersonationMember();

    // No operator session — the operator route group redirects to the operator login.
    $this->post(route('operator.impersonate', $member->id), ['organization' => $org->id])
        ->assertRedirect(route('operator.login'));

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
});

it('exits impersonation, restoring the operator and ending the subject session, audited', function (): void {
    $audit = new FakeAuditLog;
    app()->instance(AuditLog::class, $audit);

    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    // Start (real flow), then exit from the impersonated session.
    $this->withSession([OperatorAuth::SESSION_KEY => $op->id])
        ->post(route('operator.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON]);

    $subjectSessionId = session(PlatformAuth::SESSION_KEY);

    $this->post(route('impersonation.exit'))
        ->assertRedirect(route('operator.organizations'));

    // Marker cleared; subject keys gone; operator key restored.
    expect(session(Impersonation::SESSION_KEY))->toBeNull()
        ->and(session(PlatformAuth::SESSION_KEY))->toBeNull()
        ->and(session(OperatorAuth::SESSION_KEY))->toBe($op->id);

    // The subject's framework session row was revoked.
    expect(app(SessionManager::class)->active($subjectSessionId))->toBeNull();

    // Operator routes work again.
    $this->get(route('operator.organizations'))->assertOk();

    $audit->assertRecorded(
        'operator.impersonation_ended',
        fn (AuditEvent $e): bool => $e->actorType === ActorType::Operator
            && $e->actorId === $op->id
            && $e->targetId === $member->id
            && $e->organizationId === $org->id
            && array_key_exists('duration_seconds', $e->context),
    );
});

it('refuses the exit route when there is no active impersonation (403)', function (): void {
    $this->post(route('impersonation.exit'))->assertForbidden();
});

it('auto-exits once the 30-minute window has lapsed', function (): void {
    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->withSession([OperatorAuth::SESSION_KEY => $op->id])
        ->post(route('operator.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON]);

    // Backdate the start beyond the window.
    $marker = session(Impersonation::SESSION_KEY);
    $marker['started_at'] = now()->subMinutes(Impersonation::MAX_MINUTES + 1)->getTimestamp();
    session([Impersonation::SESSION_KEY => $marker]);
    session()->save();

    // The next authenticated request self-terminates and bounces to the console.
    $this->get('/dashboard')
        ->assertRedirect(route('operator.organizations'))
        ->assertSessionHas('status', 'Impersonation session expired.');

    expect(session(Impersonation::SESSION_KEY))->toBeNull()
        ->and(session(OperatorAuth::SESSION_KEY))->toBe($op->id)
        ->and(session(PlatformAuth::SESSION_KEY))->toBeNull();
});

it('blocks credential and factor changes while impersonating (403)', function (): void {
    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->withSession([OperatorAuth::SESSION_KEY => $op->id])
        ->post(route('operator.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON]);

    // The sudo step-up itself is closed.
    $this->get(route('sudo'))->assertForbidden();

    // Passkey enrolment is closed (blocked ahead of the sudo redirect).
    $this->post(route('passkeys.register.options'))->assertForbidden();
    $this->post(route('passkeys.register'))->assertForbidden();

    // Linking a social provider is closed.
    $this->get(route('social.connect', ['provider' => 'google']))->assertForbidden();
});

/*
 * Fix #2 — an operator may only impersonate a REGULAR member. Owners and admins
 * hold the tenant's full admin surface, so stepping into them is refused outright.
 */
it('refuses to impersonate an owner (403)', function (): void {
    $op = impersonationOperator();
    [$org, $owner] = impersonationMember('owner@acme.test', 'owner');

    $this->withSession([OperatorAuth::SESSION_KEY => $op->id])
        ->post(route('operator.impersonate', $owner->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON])
        ->assertForbidden();

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
});

it('refuses to impersonate an admin (403)', function (): void {
    $op = impersonationOperator();
    [$org, $admin] = impersonationMember('admin@acme.test', 'admin');

    $this->withSession([OperatorAuth::SESSION_KEY => $op->id])
        ->post(route('operator.impersonate', $admin->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON])
        ->assertForbidden();

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
});

/*
 * PAM justification — a reason is mandatory at start, is stored on the marker, and
 * is written to the start audit event.
 */
it('refuses to start impersonation without a reason', function (): void {
    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->withSession([OperatorAuth::SESSION_KEY => $op->id])
        ->post(route('operator.impersonate', $member->id), ['organization' => $org->id])
        ->assertSessionHasErrors('reason');

    // No marker was established — the whole action is refused, not partially applied.
    expect(session(Impersonation::SESSION_KEY))->toBeNull()
        ->and(session(PlatformAuth::SESSION_KEY))->toBeNull();
});

it('records the access reason on the start audit event and the marker', function (): void {
    $audit = new FakeAuditLog;
    app()->instance(AuditLog::class, $audit);

    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->withSession([OperatorAuth::SESSION_KEY => $op->id])
        ->post(route('operator.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON])
        ->assertRedirect(route('dashboard'));

    expect(session(Impersonation::SESSION_KEY)['reason'])->toBe(IMPERSONATION_REASON);

    $audit->assertRecorded(
        'operator.impersonation_started',
        fn (AuditEvent $e): bool => ($e->context['reason'] ?? null) === IMPERSONATION_REASON,
    );
});

/*
 * Fix #3 — org pivoting is closed while impersonating. The subject session is
 * pinned to the one org the operator was authorized to enter.
 */
it('blocks switching organizations while impersonating (403)', function (): void {
    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    // Give the member a second org they legitimately belong to — the escape target.
    $other = app(Organizations::class)->create(new NewOrganization('Beta', 'beta-org'));
    app(Memberships::class)->add($other->id, $member->id, 'member');

    $this->withSession([OperatorAuth::SESSION_KEY => $op->id])
        ->post(route('operator.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON]);

    $this->post(route('organization.switch'), ['organization' => $other->id])->assertForbidden();
});

/*
 * Dual-attribution audit (PAM) — the container decorator stamps the acting operator
 * onto every in-window event's context WITHOUT changing the event's real actor, so
 * the trail reads "operator X, acting as user Y, did Z".
 */
it('stamps in-window audit events with the acting operator, keeping the subject as actor', function (): void {
    $inner = new FakeAuditLog;
    $decorator = new ImpersonationAwareAuditLog($inner);

    session()->put(Impersonation::SESSION_KEY, [
        'operator' => 'op_dual',
        'subject' => 'sub_1',
        'org' => 'org_1',
        'env' => null,
        'reason' => IMPERSONATION_REASON,
        'started_at' => now()->getTimestamp(),
    ]);

    $decorator->record(new AuditEvent(
        action: 'client.created',
        actorType: ActorType::User,
        actorId: 'sub_1',
        organizationId: 'org_1',
    ));

    $recorded = $inner->recorded[0];
    expect($recorded->actorType)->toBe(ActorType::User)
        ->and($recorded->actorId)->toBe('sub_1')
        ->and($recorded->context['impersonation'] ?? null)->toBeTrue()
        ->and($recorded->context['impersonated_by'] ?? null)->toBe('op_dual');
});

it('leaves audit events untouched when not impersonating', function (): void {
    $inner = new FakeAuditLog;
    $decorator = new ImpersonationAwareAuditLog($inner);

    $decorator->record(new AuditEvent(action: 'user.login', actorType: ActorType::User, actorId: 'sub_1'));

    expect($inner->recorded[0]->context)->toBe([]);
});
