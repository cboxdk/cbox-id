<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Impersonation;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
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
function impersonatingSubject(MembershipRole $role = MembershipRole::Owner): array
{
    $subject = app(Subjects::class)->create('imp-subject@acme.test', 'Impersonated', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-impersonated'));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['impersonation']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);

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
    'clients.create (C1)' => ['console.clients.create', 'create', []],
    'device.approve (C2)' => ['device', 'approve', []],
    'oauth consent.approve (C3)' => ['oauth.consent', 'approve', []],
    // Single sign-on is three components since the console merge, so the sinks are
    // named where they now live. The connection's own page takes a mount argument and
    // is covered by its own case below rather than from this dataset.
    'connections.create (C4)' => ['console.connections.create', 'create', []],
    'connections.invite (C4)' => ['console.connections.index', 'invite', []],
    'connections.addDomain (C4)' => ['console.connections.index', 'addDomain', []],
    'connections.verifyDomain (C4)' => ['console.connections.index', 'verifyDomain', ['dom-id']],
    'connections.toggleCapture (C4)' => ['console.connections.index', 'toggleCapture', ['dom-id']],
    'connections.removeDomain (C4)' => ['console.connections.index', 'removeDomain', ['dom-id']],
    'directories.register (C5)' => ['console.directories.create', 'register', []],
    // The pull providers are reachable from both planes since the console merge, and
    // connecting one plants a sealed provider credential and starts a sync — the same
    // durable, victim-attributed state a SCIM token is.
    'directories.connectPull (C5)' => ['console.directories.create', 'connectPull', []],
    'directories.invite (C5)' => ['console.directories.index', 'invite', []],
    'webhooks.create (C6)' => ['console.webhooks.create', 'create', []],
    'members.invite (C7)' => ['members', 'invite', []],
    'members.setRole (C7)' => ['members', 'setRole', ['some-user', 'admin']],
    'members.remove (C7)' => ['members', 'remove', ['some-user']],
    'roles.create (C7)' => ['console.roles.create', 'create', []],
    'roles.grant (C7)' => ['console.roles.index', 'grant', ['some-role']],
    'settings.rename (L1)' => ['console.settings', 'rename', []],
]);

it('refuses every durable-access console action while impersonating (403)', function (string $component, string $method, array $args): void {
    impersonatingSubject();

    Volt::test($component)->call($method, ...$args)->assertStatus(403);
})->with('durable_access_sinks');

it('refuses every SSO connection lifecycle action while impersonating (403)', function (string $method): void {
    [, $org] = impersonatingSubject();

    // A real connection, because the detail page resolves its model at mount and would
    // otherwise 404 before the guard could refuse — which would pass this test while
    // proving nothing. Its lifecycle actions are the sinks the console merge gave this
    // plane: an impersonating operator who could rewrite the IdP config would be
    // redirecting where the victim's whole company authenticates.
    $connection = app(Connections::class)->create(
        $org->id,
        ConnectionType::Saml,
        'Corporate SAML',
        ['idp_entity_id' => 'https://idp.corp/metadata'],
    );

    Volt::test('console.connections.show', ['connection' => $connection->id])
        ->call($method)
        ->assertStatus(403);
})->with(['saveConfig', 'activate', 'disable', 'deleteConnection']);

it('still allows read-only navigation while impersonating', function (): void {
    impersonatingSubject();

    // Paginating a read-only list (the audit trail) is allowlisted.
    Volt::test('console.audit')->call('nextPage')->assertStatus(200);

    // A magic property set (filtering, tab toggles) is allowlisted — it only mutates
    // in-memory component state, never durable tenant data.
    Volt::test('members')->call('$set', 'inviteEmail', 'x@acme.test')->assertStatus(200);
});

it('still lets a full page load render while impersonating', function (): void {
    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->post(route('operator.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON]);

    $this->get('/dashboard')->assertOk();
});

it('still lets the operator exit impersonation', function (): void {
    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->post(route('operator.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON]);

    // Exit is a plain controller POST, not a Livewire action, so the read-only hook
    // never touches it — the escape hatch always works.
    $this->post(route('impersonation.exit'))->assertRedirect(route('operator.organizations'));

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
});
