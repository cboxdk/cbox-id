<?php

declare(strict_types=1);

use App\Platform\Impersonation;
use App\Platform\ImpersonationAwareAuditLog;
use App\Platform\OperatorEnvironment;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Testing\FakeAuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;

it('lets an operator step into a member and become purely the subject, audited', function (): void {
    $audit = new FakeAuditLog;
    app()->instance(AuditLog::class, $audit);

    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->post(route('platform.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON])
        ->assertRedirect(route('dashboard'));

    // The browser is now the subject: the impersonation marker is present, and the ONE
    // signed-in identity is the member's — not the operator's, and not both.
    expect(session(Impersonation::SESSION_KEY))->not->toBeNull()
        ->and(session(PlatformAuth::SESSION_KEY))->not->toBeNull()
        ->and(session(PlatformAuth::ACTIVE_KEY))->toBe($member->id)
        ->and(array_keys((array) session(PlatformAuth::ACCOUNTS_KEY)))->toBe([$member->id]);

    // The subject session authenticated with an `impersonation` amr — never a login.
    $sessionId = session(PlatformAuth::SESSION_KEY);
    $row = app(SessionManager::class)->active($sessionId);
    expect($row)->not->toBeNull()
        ->and($row->user_id)->toBe($member->id)
        ->and($row->amr)->toContain('impersonation');

    nextRequest();

    // The platform pages are unreachable while impersonating — and they 404 rather than
    // 403, because the session IS a real signed-in session and a 403 would tell the
    // person holding it that a staff console exists at that address.
    $this->get(route('platform.organizations'))->assertNotFound();

    // The dashboard loads as the subject and shows the impersonation banner.
    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('member@acme.test')
        ->assertSee('impersonating', false);

    $audit->assertRecorded(
        'platform.impersonation_started',
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
    $this->post(route('platform.impersonate', $stranger->id), ['organization' => $org->id])
        ->assertForbidden();

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
});

it('refuses to impersonate a member of an org in another plane (403)', function (): void {
    $op = impersonationOperator();

    // Pin the operator to a plane explicitly (its Environment row must exist for the
    // slug lookup in TargetEnvironment to resolve it).
    Environment::query()->create(['name' => 'Default', 'slug' => 'env_test', 'status' => 'active']);
    targetEnvironment('env_test');

    // Org + member live entirely in ANOTHER plane. Memberships::of is plane-scoped
    // to the operator's pinned plane → resolves to null → 403.
    $env = Environment::query()->create(['name' => 'Other', 'slug' => 'other-env', 'status' => 'active']);
    [$foreignOrgId, $foreignUserId] = app(EnvironmentContext::class)->runAs($env, function (): array {
        $org = app(Organizations::class)->create(new NewOrganization('Foreign', 'foreign'));
        $subject = app(Subjects::class)->create('foreign@acme.test', 'F', 'supersecret123');
        app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

        return [$org->id, $subject->id];
    });

    $this->post(route('platform.impersonate', $foreignUserId), ['organization' => $foreignOrgId])
        ->assertForbidden();

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
});

it('refuses a non-operator hitting the start route', function (): void {
    [$org, $member] = impersonationMember();

    // Nobody is signed in at all, so the gate offers the step a visitor can take: the
    // one sign-in. (Signed in but not an operator is the OTHER refusal — a 404.)
    $this->post(route('platform.impersonate', $member->id), ['organization' => $org->id])
        ->assertRedirect(route('login'));

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
});

it('exits impersonation, restoring the operator and ending the subject session, audited', function (): void {
    $audit = new FakeAuditLog;
    app()->instance(AuditLog::class, $audit);

    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    // Start (real flow), then exit from the impersonated session.
    $this->post(route('platform.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON]);

    $subjectSessionId = session(PlatformAuth::SESSION_KEY);

    $this->post(route('impersonation.exit'))
        ->assertRedirect(route('platform.organizations'));

    // Marker cleared; the impersonated subject is gone from the browser; the OPERATOR's
    // own subject session — the same one, resumed, not a fresh one minted on their
    // behalf — is the signed-in identity again.
    expect(session(Impersonation::SESSION_KEY))->toBeNull()
        ->and(session(PlatformAuth::ACTIVE_KEY))->toBe($op->subject_id)
        ->and(array_keys((array) session(PlatformAuth::ACCOUNTS_KEY)))->toBe([$op->subject_id]);

    // The subject's framework session row was revoked.
    expect(app(SessionManager::class)->active($subjectSessionId))->toBeNull();

    // Operator routes work again — asked fresh, so this is the restored session
    // answering and not a memo from before the impersonation.
    nextRequest();
    $this->get(route('platform.organizations'))->assertOk();

    $audit->assertRecorded(
        'platform.impersonation_ended',
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

/*
 * The single most dangerous outcome of making the operator a subject.
 *
 * "Suspend the operator" used to mean forgetting one session key, because that key WAS
 * the operator. Authority now rides the ordinary subject session — and PlatformAuth
 * holds SEVERAL signed-in accounts at once and switches between them with no
 * re-authentication. So an operator merely made inactive, rather than removed from the
 * browser, is one switch away from full platform authority INSIDE the session they are
 * supposedly not in — and every audit event in that window is attributed to the person
 * they stepped into.
 *
 * The Livewire call guard refuses the switch action too. That is a second layer, and it
 * is a deny-list on a seam; this test asserts the first one, by making the switch
 * directly rather than through the component that the guard happens to cover.
 */
it('leaves an impersonated session no way back into operator authority', function (): void {
    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->post(route('platform.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON])
        ->assertRedirect(route('dashboard'));

    // Not signed in as the operator, and not holding them aside either. Attempted with
    // the request's own container still standing (see nextRequest) — under a torn-down
    // one this refusal happens for a reason that has nothing to do with the operator.
    expect(app(PlatformAuth::class)->switchTo(request(), (string) $op->subject_id))->toBeFalse()
        ->and(session(PlatformAuth::ACTIVE_KEY))->toBe($member->id)
        ->and(array_keys((array) session(PlatformAuth::ACCOUNTS_KEY)))->toBe([$member->id]);

    // …so the platform pages stay gone after the attempt, asked fresh.
    nextRequest();
    $this->get(route('platform.organizations'))->assertNotFound();
    $this->get(route('platform.environments'))->assertNotFound();
});

/*
 * The door staff actually use.
 *
 * Operator authority is resolved from the ONE session, and the account console is where
 * staff sign in — so the session an impersonation has to put aside is the very session
 * that was holding the operator's authority. This used to be two stores, and suspending
 * only one LOOKED safe because the resolver preferred the subject session, which during
 * an impersonation is the victim's. It is one store now, which is why this test can be
 * written as "the operator comes back usable" rather than as "and the other key too".
 */
it('suspends the session an operator signed in with, and puts it back', function (): void {
    $op = impersonationOperator('staff@platform.test');
    [$org, $member] = impersonationMember();

    // The same person, also an account member — the shape a browser is really in when
    // staff have opened the account console and signed in there.
    $account = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        accountName: 'Cbox',
        ownerEmail: 'staff@platform.test',
        ownerName: 'Staff',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ))->member;

    expect($account->subject_id)->toBe($op->subject_id, 'fixture: the operator and the member must be one person');

    // Signed in the way the account door does it.
    signInAsMember($account);

    $this->post(route('platform.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON])
        ->assertRedirect(route('dashboard'));

    // The staff member is gone from the browser, not merely outranked by the victim.
    expect(session(PlatformAuth::ACTIVE_KEY))->toBe($member->id)
        ->and(array_keys((array) session(PlatformAuth::ACCOUNTS_KEY)))->toBe([$member->id]);

    nextRequest();
    $this->get(route('platform.organizations'))->assertNotFound();

    $this->post(route('impersonation.exit'))->assertRedirect(route('platform.organizations'));

    // Back, and USABLE — asserted through the resolver rather than on a raw key, because
    // a restore that puts back an id without a live session row is a session the plane
    // refuses, and asserting the key alone would not notice.
    nextRequest();
    $this->get(route('projects'))->assertOk();
});

/*
 * The plane the console was aimed at is a preference, and it comes back with the
 * operator: an operator who was working inside a customer's environment when they
 * stepped in must not land back on some other plane's data with the same page open.
 * It is also SUSPENDED for the duration — while impersonating there is no operator to
 * have a preference.
 */
it('restores the operator and the plane they had targeted, on exit', function (): void {
    $root = platformRootDeployment();
    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    targetEnvironment($root->slug);

    $this->post(route('platform.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON])
        ->assertRedirect(route('dashboard'));

    expect(session(OperatorEnvironment::SESSION_KEY))->toBeNull();

    $this->post(route('impersonation.exit'))->assertRedirect(route('platform.organizations'));

    expect(session(OperatorEnvironment::SESSION_KEY))->toBe($root->slug)
        ->and(session(PlatformAuth::ACTIVE_KEY))->toBe($op->subject_id);

    nextRequest();
    $this->get(route('platform.organizations'))->assertOk();
});

it('auto-exits once the 30-minute window has lapsed', function (): void {
    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->post(route('platform.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON]);

    // Backdate the start beyond the window.
    $marker = session(Impersonation::SESSION_KEY);
    $marker['started_at'] = now()->subMinutes(Impersonation::MAX_MINUTES + 1)->getTimestamp();
    session([Impersonation::SESSION_KEY => $marker]);
    session()->save();

    // The next authenticated request self-terminates and bounces to the console.
    $this->get('/dashboard')
        ->assertRedirect(route('platform.organizations'))
        ->assertSessionHas('status', 'Impersonation session expired.');

    expect(session(Impersonation::SESSION_KEY))->toBeNull()
        ->and(session(PlatformAuth::ACTIVE_KEY))->toBe($op->subject_id);
});

it('blocks credential and factor changes while impersonating (403)', function (): void {
    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->post(route('platform.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON]);

    // The sudo step-up itself is closed.
    $this->get(route('sudo'))->assertForbidden();

    // Passkey enrolment is closed (blocked ahead of the sudo redirect).
    $this->post(route('passkeys.register.options'))->assertForbidden();
    $this->post(route('passkeys.register'))->assertForbidden();

    // Linking a social provider is closed — at both the start AND the callback,
    // where the durable link is actually established.
    $this->get(route('social.connect', ['provider' => 'google']))->assertForbidden();
    $this->get(route('social.connect.callback', ['provider' => 'google']))->assertForbidden();
});

/*
 * Fix #2 — an operator may only impersonate a REGULAR member. Owners and admins
 * hold the tenant's full admin surface, so stepping into them is refused outright.
 */
it('refuses to impersonate an owner (403)', function (): void {
    $op = impersonationOperator();
    [$org, $owner] = impersonationMember('owner@acme.test', MembershipRole::Owner);

    $this->post(route('platform.impersonate', $owner->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON])
        ->assertForbidden();

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
});

it('refuses to impersonate an admin (403)', function (): void {
    $op = impersonationOperator();
    [$org, $admin] = impersonationMember('admin@acme.test', MembershipRole::Admin);

    $this->post(route('platform.impersonate', $admin->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON])
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

    $this->post(route('platform.impersonate', $member->id), ['organization' => $org->id])
        ->assertSessionHasErrors('reason');

    // No marker was established, and no subject session was minted — the whole action is
    // refused, not partially applied. The operator is still signed in as themselves.
    expect(session(Impersonation::SESSION_KEY))->toBeNull()
        ->and(session(PlatformAuth::ACTIVE_KEY))->toBe($op->subject_id);
});

it('records the access reason on the start audit event and the marker', function (): void {
    $audit = new FakeAuditLog;
    app()->instance(AuditLog::class, $audit);

    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->post(route('platform.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON])
        ->assertRedirect(route('dashboard'));

    expect(session(Impersonation::SESSION_KEY)['reason'])->toBe(IMPERSONATION_REASON);

    $audit->assertRecorded(
        'platform.impersonation_started',
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
    app(Memberships::class)->add($other->id, $member->id, MembershipRole::Member);

    $this->post(route('platform.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON]);

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

/**
 * The one credential an impersonator could still mint.
 *
 * BlockDuringImpersonation sits on every other credential-establishing route — password
 * reset, invitation, email verification, sudo, org switch, passkey registration, social
 * connect. /oauth/authorize was the exception, and it issues the longest-lived credential
 * of the set: a refresh token that outlives both the 30-minute window and the operator's
 * own session, and that is attributed to the person being impersonated.
 *
 * ImpersonationCallGuard did not cover it either. That guard hangs off Livewire's `call`
 * event and its comment claims deny-by-default means "no sink can be missed" — but
 * mount() is not a call, and the consent component reaches approve() from inside mount()
 * whenever the client is first-party. So a plain GET issued a code with no Livewire
 * action for the guard to refuse.
 *
 * The redirect target never has to be reachable: the impersonator IS the user agent and
 * reads `code` straight out of the Location header.
 */
it('refuses to issue an authorization code while impersonating', function (): void {
    $op = impersonationOperator();
    [$org, $member] = impersonationMember('authz-victim@acme.test');

    $client = app(ClientRegistry::class)->register(new NewClient(
        'First Party App',
        redirectUris: ['https://app.test/cb'],
        organizationId: null,
        firstParty: true,
    ))->client;

    $this->post(route('platform.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON])
        ->assertRedirect(route('dashboard'));

    $response = $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->client_id,
        'response_type' => 'code',
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid offline_access',
        'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
        'code_challenge_method' => 'S256',
    ]));

    expect((string) $response->headers->get('Location'))->not->toContain('code=');
    expect($response->getStatusCode())->not->toBe(200);
});
