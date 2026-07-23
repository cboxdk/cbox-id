<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Impersonation;
use App\Platform\OperatorAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Subject;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Volt\Volt;

/**
 * Establish an impersonated subject session and an active marker, then drive the
 * console components directly. The subject is deliberately given the OWNER role so
 * every admin-gated component mounts — this proves the read-only Livewire guard
 * blocks mutations on its own, independent of the "no owner/admin impersonation"
 * gate. In production an operator can never step into an owner (see
 * ImpersonationTest); here we hold the strongest possible privilege and show the
 * durable-access sinks are STILL refused.
 *
 * @return array{0: Subject, 1: Organization}
 */
function impersonatingSubject(string $role = 'owner'): array
{
    $subject = app(Subjects::class)->create('imp-subject@acme.test', 'Impersonated', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-impersonated'));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['impersonation']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::from($role));

    session()->put(Impersonation::SESSION_KEY, [
        'operator' => 'op_readonly',
        'subject' => $subject->id,
        'org' => $org->id,
        'env' => null,
        'reason' => 'Investigating support ticket #4271',
        'started_at' => now()->getTimestamp(),
    ]);

    return [$subject, $org];
}

/*
 * The review's confirmed durable-access sinks. Each is a Livewire component action
 * that, unguarded, would let an impersonating operator plant durable, victim-
 * attributed state (an OAuth client + secret, a device/consent grant, an SSO
 * connection, a SCIM token, a webhook + secret, a membership/role change, branding).
 * The `call` hook fires BEFORE the method body, so the arguments are immaterial —
 * every one must 403.
 */
dataset('durable_access_sinks', [
    'clients.create (C1)' => ['clients', 'create', []],
    'device.approve (C2)' => ['device', 'approve', []],
    'oauth consent.approve (C3)' => ['oauth.consent', 'approve', []],
    'connections.create (C4)' => ['connections', 'create', []],
    'connections.activate (C4)' => ['connections', 'activate', ['dom-id']],
    'connections.invite (C4)' => ['connections', 'invite', []],
    'connections.addDomain (C4)' => ['connections', 'addDomain', []],
    'connections.verifyDomain (C4)' => ['connections', 'verifyDomain', ['dom-id']],
    'connections.toggleCapture (C4)' => ['connections', 'toggleCapture', ['dom-id']],
    'connections.removeDomain (C4)' => ['connections', 'removeDomain', ['dom-id']],
    'directories.register (C5)' => ['directories', 'register', []],
    'directories.invite (C5)' => ['directories', 'invite', []],
    'webhooks.create (C6)' => ['webhooks', 'create', []],
    'members.invite (C7)' => ['members', 'invite', []],
    'members.setRole (C7)' => ['members', 'setRole', ['some-user', 'admin']],
    'members.remove (C7)' => ['members', 'remove', ['some-user']],
    'roles.create (C7)' => ['roles', 'create', []],
    'roles.grant (C7)' => ['roles', 'grant', ['some-role']],
    'settings.saveBranding (L1)' => ['settings', 'saveBranding', []],
]);

it('refuses every durable-access console action while impersonating (403)', function (string $component, string $method, array $args): void {
    impersonatingSubject();

    Volt::test($component)->call($method, ...$args)->assertStatus(403);
})->with('durable_access_sinks');

it('still allows read-only navigation while impersonating', function (): void {
    impersonatingSubject();

    // Paginating a read-only list (the audit trail) is allowlisted.
    Volt::test('audit')->call('nextPage')->assertStatus(200);

    // A magic property set (filtering, tab toggles) is allowlisted — it only mutates
    // in-memory component state, never durable tenant data.
    Volt::test('members')->call('$set', 'inviteEmail', 'x@acme.test')->assertStatus(200);
});

it('still lets a full page load render while impersonating', function (): void {
    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->withSession([OperatorAuth::SESSION_KEY => $op->id])
        ->post(route('operator.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON]);

    $this->get('/dashboard')->assertOk();
});

it('still lets the operator exit impersonation', function (): void {
    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->withSession([OperatorAuth::SESSION_KEY => $op->id])
        ->post(route('operator.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON]);

    // Exit is a plain controller POST, not a Livewire action, so the read-only hook
    // never touches it — the escape hatch always works.
    $this->post(route('impersonation.exit'))->assertRedirect(route('operator.organizations'));

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
});
