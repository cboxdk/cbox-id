<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Impersonation;
use App\Platform\PlatformAuth;
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

    /*
     * AND THE SESSION KEY THE CONSOLE'S GUARD READS ON THE WAY IN. `CurrentUser` is
     * resolved state for code already inside the process, which is all a Livewire
     * component ever needed. A ported page is reached by a REQUEST, and without this every
     * GET answers a redirect to sign-in — so a test asserting "not refused" would be
     * asserting against a bounce to /login.
     */
    session([PlatformAuth::SESSION_KEY => $session->id]);

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
 * THE LIVEWIRE `call` SWEEP IS GONE, and it is worth saying what it was.
 *
 * Every console mutation used to be a component action POSTed to one endpoint that route
 * middleware never saw, so the impersonation guard had to hang off Livewire's `call` event
 * and name each sink: an OAuth client and secret, a device or consent grant, an SSO
 * connection, a SCIM token, a webhook, a role change, branding. The arguments were
 * immaterial — the hook fired before the method body — and every one had to 403.
 *
 * There is no such seam left. Each of those is a route with a verb, and
 * {@see \App\Http\Middleware\ReadOnlyWhileImpersonating} refuses on the METHOD, which is
 * deny-by-default rather than an allowlist of action names: a write added tomorrow is
 * refused without anybody remembering this list exists. The two sinks that were still Volt
 * — the device grant and the consent screen — are in `ported_console_writes` below, where
 * they are driven as the requests they now are.
 */

/*
|--------------------------------------------------------------------------
| The ported pages
|--------------------------------------------------------------------------
| THE SAME PROPERTY, ASKED OF THE THING THAT NOW ANSWERS IT.
|
| The guard above lives at Livewire's `call` seam, because every console mutation was a
| component action POSTed to one endpoint and route middleware never saw it. A ported page
| has no such seam: each mutation is its own request with its own verb, and
| {@see \App\Http\Middleware\ReadOnlyWhileImpersonating} refuses on the METHOD.
|
| That is deny-by-default rather than an allowlist of action names — a write added
| tomorrow is refused without anybody remembering to think about it — and this is where
| that claim is checked. Each case below is a route the console genuinely serves; the
| parameters are deliberately nonsense, because the refusal must land BEFORE anything
| resolves them.
*/
dataset('ported_console_writes', [
    // C2. The device grant: an impersonator approving one would plant a durable,
    // victim-attributed credential on hardware the victim does not hold.
    'device.approve (C2)' => ['post', 'device.approve', []],
    /*
     * C3. The consent screen, which issues the LONGEST-LIVED credential in the product: a
     * refresh token that outlives both the impersonation window and the operator's own
     * session, attributed to the person being impersonated.
     */
    'oauth.authorize.approve (C3)' => ['post', 'oauth.authorize.approve', ['authorization' => 'no-such-request']],
    'oauth.authorize.deny (C3)' => ['post', 'oauth.authorize.deny', ['authorization' => 'no-such-request']],
    'device.deny (C2)' => ['post', 'device.deny', []],
    'device.lookup (C2)' => ['post', 'device.lookup', []],
    'webhooks.store (C6)' => ['post', 'webhooks.store', []],
    'webhooks.destroy (C6)' => ['delete', 'webhooks.destroy', ['webhook' => 'no-such-endpoint']],
    'members.invite (C7)' => ['post', 'members.invite', []],
    'members.role (C7)' => ['patch', 'members.role', ['member' => 'no-such-membership']],
    'members.remove (C7)' => ['delete', 'members.remove', ['member' => 'no-such-membership']],
    'members.access (C7)' => ['put', 'members.access', ['member' => 'no-such-membership']],
    'members.transfer-ownership (C7)' => ['post', 'members.transfer-ownership', ['member' => 'no-such-membership']],
    'api-keys.store' => ['post', 'api-keys.store', []],
    'api-keys.destroy' => ['delete', 'api-keys.destroy', ['key' => 'no-such-key']],
    'projects.store' => ['post', 'projects.store', []],
    'projects.environments.store' => ['post', 'projects.environments.store', ['project' => 'no-such-project']],
    'settings.rename (L1)' => ['patch', 'settings.rename', []],
    'clients.store (C1)' => ['post', 'clients.store', []],
    'clients.update (C1)' => ['patch', 'clients.update', ['client' => 'no-such-app']],
    'clients.rotate (C1)' => ['post', 'clients.rotate', ['client' => 'no-such-app']],
    'clients.destroy (C1)' => ['delete', 'clients.destroy', ['client' => 'no-such-app']],
    'clients.manifest (C1)' => ['put', 'clients.manifest', ['client' => 'no-such-app']],
    'clients.sync (C1)' => ['post', 'clients.sync', ['client' => 'no-such-app']],
    'connections.store (C4)' => ['post', 'connections.store', []],
    'connections.update (C4)' => ['patch', 'connections.update', ['connection' => 'no-such-connection']],
    'connections.activate (C4)' => ['post', 'connections.activate', ['connection' => 'no-such-connection']],
    'connections.disable (C4)' => ['post', 'connections.disable', ['connection' => 'no-such-connection']],
    'connections.require-sso (C4)' => ['post', 'connections.require-sso', ['connection' => 'no-such-connection']],
    'connections.destroy (C4)' => ['delete', 'connections.destroy', ['connection' => 'no-such-connection']],
    'connections.invite (C4)' => ['post', 'connections.invite', []],
    'connections.domains.store (C4)' => ['post', 'connections.domains.store', []],
    'connections.domains.verify (C4)' => ['post', 'connections.domains.verify', ['domain' => 'no-such-domain']],
    'connections.domains.capture (C4)' => ['post', 'connections.domains.capture', ['domain' => 'no-such-domain']],
    'connections.domains.destroy (C4)' => ['delete', 'connections.domains.destroy', ['domain' => 'no-such-domain']],
    'directories.store (C5)' => ['post', 'directories.store', []],
    'directories.connect (C5)' => ['post', 'directories.connect', []],
    'directories.invite (C5)' => ['post', 'directories.invite', []],
    'directories.update (C5)' => ['patch', 'directories.update', ['directory' => 'no-such-directory']],
    'directories.rotate (C5)' => ['post', 'directories.rotate', ['directory' => 'no-such-directory']],
    'directories.toggle (C5)' => ['post', 'directories.toggle', ['directory' => 'no-such-directory']],
    'directories.map (C5)' => ['post', 'directories.map', ['directory' => 'no-such-directory']],
    'directories.destroy (C5)' => ['delete', 'directories.destroy', ['directory' => 'no-such-directory']],
    'roles.store (C7)' => ['post', 'roles.store', []],
    'roles.update (C7)' => ['patch', 'roles.update', ['role' => 'no-such-role']],
    'roles.permissions (C7)' => ['post', 'roles.permissions', ['role' => 'no-such-role']],
    'roles.destroy (C7)' => ['delete', 'roles.destroy', ['role' => 'no-such-role']],
    // The organization's own roster — a membership change is durable, victim-attributed
    // state, and inviting mints a token that admits a stranger to the victim's tenant.
    'directory.members.invite (C7)' => ['post', 'directory.members.invite', []],
    'directory.members.role (C7)' => ['patch', 'directory.members.role', ['member' => 'no-such-member']],
    'directory.members.access (C7)' => ['post', 'directory.members.access', ['member' => 'no-such-member']],
    'directory.members.remove (C7)' => ['delete', 'directory.members.remove', ['member' => 'no-such-member']],
    'directory.members.invitations.revoke (C7)' => ['delete', 'directory.members.invitations.revoke', ['invitation' => 'no-such-invitation']],
]);

it('refuses every ported console write while impersonating (403)', function (string $verb, string $name, array $parameters): void {
    impersonationOperator();
    [$org, $member] = impersonationMember();

    // THE REAL FLOW, not a hand-set session key: the middleware asks
    // {@see \App\Platform\Impersonation} whether this browser is impersonating, and a
    // fixture that writes the key by hand would be asserting against its own construction.
    $this->post(route('platform.impersonate', $member->id), [
        'organization' => $org->id,
        'reason' => IMPERSONATION_REASON,
    ]);

    $response = $this->{$verb}(route($name, $parameters));

    /*
     * THE REASON, NOT THE STATUS.
     *
     * Three of these routes answer 403 on their own merits — the impersonated person is
     * an ordinary Member, and a member may not mint a webhook or revoke an API key. With
     * the guard deleted those three still returned 403 and this test still passed, which
     * is the failure mode it exists to catch. The sentence is the middleware's own.
     */
    $response->assertForbidden();

    expect($response->exception?->getMessage())
        ->toBe('This action is not available while impersonating a user.');
})->with('ported_console_writes')->group('security');

it('leaves exactly two doors open while impersonating, and they are both ways out', function (): void {
    impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->post(route('platform.impersonate', $member->id), [
        'organization' => $org->id,
        'reason' => IMPERSONATION_REASON,
    ]);

    // Reading is untouched — support is meant to be able to SEE what the person sees.
    $this->get(route('dashboard'))->assertOk();

    // And signing out works, which is the other way out. Exiting has its own test below.
    $this->post(route('logout'))->assertRedirect();
})->group('security');

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

    $response = match ($method) {
        'update' => $this->patch(route('connections.update', $connection->id), ['name' => 'Renamed']),
        'destroy' => $this->delete(route('connections.destroy', $connection->id)),
        default => $this->post(route('connections.'.$method, $connection->id)),
    };

    $response->assertForbidden();

    expect($response->exception?->getMessage())
        ->toBe('This action is not available while impersonating a user.');
})->with(['update', 'activate', 'disable', 'destroy']);

it('still allows read-only navigation while impersonating', function (): void {
    impersonatingSubject();

    // A ported list pages with a GET, which the guard lets through on the method alone —
    // the whole point of refusing on the verb rather than on an allowlist of action names.
    test()->get(route('roles', ['page' => 2]))->assertOk();

    // …and a GET of the account page too, which is the read an operator most often wants
    // while standing in somebody else's session. The Volt half of this assertion — a magic
    // property set, allowlisted because it only touched in-memory component state — went
    // with the component: there is no such thing under Inertia, where every interaction is
    // a request the guard judges on its verb.
    test()->get(route('account'))->assertOk();
});

it('still lets a full page load render while impersonating', function (): void {
    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->post(route('platform.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON]);

    $this->get('/dashboard')->assertOk();
});

it('still lets the operator exit impersonation', function (): void {
    $op = impersonationOperator();
    [$org, $member] = impersonationMember();

    $this->post(route('platform.impersonate', $member->id), ['organization' => $org->id, 'reason' => IMPERSONATION_REASON]);

    // Exit is a plain controller POST, not a Livewire action, so the read-only hook
    // never touches it — the escape hatch always works.
    $this->post(route('impersonation.exit'))->assertRedirect(route('platform.organizations'));

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
});
