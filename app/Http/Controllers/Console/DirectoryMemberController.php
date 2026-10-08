<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Account\LeaveOrganization;
use App\Actions\Invitations\ResendInvitation;
use App\Actions\Invitations\RevokeInvitation;
use App\Actions\Invitations\SendInvitation;
use App\Actions\Members\ChangeMemberRole;
use App\Actions\Members\GrantMemberRole;
use App\Actions\Members\RemoveMember;
use App\Actions\Members\RevokeMemberRole;
use App\Actions\Members\TenantRoster;
use App\Actions\Organizations\TransferOwnership;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Props\Shared\PendingInvitationProps;
use App\Http\Props\Shared\ReturnAppProps;
use App\Http\Props\Shared\RoleOptionProps;
use App\Http\Requests\Console\InviteOrganizationMemberRequest;
use App\Platform\Actions\ActionRefused;
use App\Platform\Console\Vocabulary;
use App\Platform\CurrentUser;
use App\Platform\Help\HelpTopic;
use App\Platform\Invitations\AppReturnTargets;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\ValueObjects\PendingInvitationSummary;
use App\Platform\Invitations\ValueObjects\SentInvitation;
use App\Platform\Membership\AfterLeaving;
use App\Platform\OrgAccessRoles;
use App\Platform\OrgRoles;
use Carbon\CarbonInterface;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Response;

/**
 * PEOPLE — everyone who can sign in to this organization, and the invitations nobody has
 * accepted yet.
 *
 * NOT the Identity-platform administrators page beside it ({@see MemberController}), which
 * is the ACCOUNT's own team. Both were once registered on `/members` with the name
 * `members`; Laravel keys the route collection on `method|domain|uri`, so the second
 * registration replaced the first and this page was unreachable from any URL for a while.
 *
 * TWO KINDS OF ACCESS ON EVERY ROW, and they answer different questions: the membership
 * role governs what a person may administer HERE, and the access roles are what they can do
 * inside this organization's apps. Conflating them is how somebody ends up an owner in
 * order to read a report.
 *
 * EVERY WRITE IS AN ACTION — the same ones the environment console, the management API and
 * an agent run, so a person signed in here and a token one of them signed in for are held to
 * the same rules. What only applies from inside the organization (an admin cannot touch an
 * owner, nobody removes themselves, a customer's roster is administered elsewhere) is the
 * actions' own, asked of whoever is confined to it ({@see TenantRoster}). Leaving is the
 * person's own act, on the account plane ({@see LeaveOrganization}).
 */
final readonly class DirectoryMemberController extends ConsoleController
{
    public function index(Memberships $memberships, Subjects $subjects, OrganizationInvitations $invitations, AppReturnTargets $targets): Response
    {
        $me = app(CurrentUser::class);
        $organizationId = $this->organizationId();

        $page = $memberships->paginateForOrganization($organizationId);

        /** @var list<Membership> $roster */
        $roster = $page->items();

        /** @var list<string> $userIds */
        $userIds = array_map(static fn (Membership $m): string => (string) $m->user_id, $roster);

        // Batch-resolved in ONE query rather than a `find()` per row; pagination keeps the
        // roster query bounded regardless of how large the organization gets.
        $subjectsById = $subjects->findMany($userIds);

        /*
         * THE ACCESS-ROLE HALF IS AN ADMINISTRATOR'S. Every member may see who else is
         * here and at which tier — that is what a roster is for — but which app roles
         * each colleague holds, and the permission catalogue behind every role, is the
         * organization's authorization model: what to ask for, whom to target, which
         * account opens what. A plain member has no control on this page that uses it
         * (every editor is gated on `isAdmin` in the client), so it was being shipped in
         * the page props only to be hidden by the UI, which is not hiding it at all.
         *
         * Not queried either, rather than queried and dropped: what is never loaded can
         * never leak through a prop somebody adds later.
         */
        $isAdmin = $me->isAdmin();
        $accessRoles = $isAdmin ? $this->assignableRoles($organizationId) : null;
        $assignments = $accessRoles !== null ? $this->assignmentsByUser($organizationId, $userIds) : [];

        $rows = [];

        foreach ($roster as $membership) {
            $userId = (string) $membership->user_id;
            $subject = $subjectsById[$userId] ?? null;

            // The package's Membership model does not declare the Eloquent timestamp
            // columns, so read it off the attribute bag and narrow it rather than trusting
            // an undeclared property.
            $joined = $membership->getAttribute('created_at');

            $rows[] = [
                'id' => $userId,
                'name' => $subject?->name,
                'email' => $subject?->email,
                'role' => $membership->role->value,
                'accessRoleIds' => $assignments[$userId] ?? [],
                'joined' => $joined instanceof CarbonInterface ? $joined->format('M j, Y') : null,
                'isMe' => $userId === $me->id(),
                'urls' => [
                    'role' => route('directory.members.role', $userId),
                    'access' => route('directory.members.access', $userId),
                    'remove' => route('directory.members.remove', $userId),
                    'transfer' => route('directory.members.transfer-ownership', $userId),
                ],
            ];
        }

        return $this->page('console/directory-members', Vocabulary::MEMBERS, [
            'isAdmin' => $isAdmin,
            'members' => $rows,
            'pagination' => PaginationProps::from($page),
            // Only an admin sees the pending list at all: an invitation names an address
            // somebody chose to invite, which is not a plain member's business.
            'invitations' => $me->isAdmin() ? array_map(
                static fn (PendingInvitationSummary $pending): PendingInvitationProps => PendingInvitationProps::from(
                    $pending,
                    route('directory.members.invitations.resend', $pending->id),
                    route('directory.members.invitations.revoke', $pending->id),
                ),
                $invitations->pending($organizationId),
            ) : [],
            'accessRoles' => $accessRoles !== null
                ? $this->accessRoleProps($accessRoles, $this->appNames($accessRoles), $this->permissionsByRole($accessRoles))
                : [],
            // ONE list, the same one the environment console offers for this organization.
            // The roster's copy names Owner so an owner's row can say what it holds; it is
            // never offered — ownership moves by transfer.
            'roleOptions' => RoleOptionProps::organization(),
            'rosterRoleOptions' => RoleOptionProps::organization(withOwner: true),
            'apps' => $me->isAdmin() ? array_map(ReturnAppProps::from(...), $targets->appsFor($organizationId)) : [],
            'isOwner' => $me->isOwner(),
            /*
             * WHOSE ROSTER THIS IS. An organization that owns PRODUCTS is a customer of this
             * platform, and a customer's roster is administered from the management console
             * — by somebody holding an organization capability — rather than from here. Said
             * on the page rather than only enforced on the write, so an admin is not left
             * clicking controls that refuse.
             */
            'managedElsewhere' => TenantRoster::managedElsewhere($organizationId),
            'rolesHref' => route('roles'),
            'inviteHref' => route('directory.members.invite'),
            'leaveHref' => route('directory.members.leave'),
            'organizationName' => $me->organization()->name ?? '',
            'help' => HelpProps::for(HelpTopic::Members),
        ]);
    }

    /**
     * A PENDING invitation — membership is granted only when the invitee accepts the emailed
     * token. Nobody is added without consent. The same action the environment console and an
     * app's backend send one with ({@see SendInvitation}), signed with this person's name;
     * from in here it refuses a customer's roster, which is administered elsewhere.
     */
    public function invite(InviteOrganizationMemberRequest $request): RedirectResponse
    {
        $this->assertAdmin();

        $result = $this->act(SendInvitation::class, [
            'organization_id' => $this->organizationId(),
            'email' => $request->email(),
            'role' => $request->role()->value,
            'roles' => $request->accessRoleIds(),
            'client_id' => $request->filled('client_id') ? $request->string('client_id')->toString() : null,
            'return_to' => $request->filled('return_to') ? $request->string('return_to')->toString() : null,
        ], ['email' => 'email', 'role' => 'role', 'roles' => 'accessRoles', 'client_id' => 'client_id', 'return_to' => 'return_to'], 'email', [
            // The picker's own sentence: the person chose from a list, not by id.
            'role_not_assignable' => OrgAccessRoles::NOT_OFFERED,
            'unknown_role' => OrgAccessRoles::NOT_OFFERED,
        ]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return back()->with('status', 'Invitation sent to '.$request->email().'.');
    }

    /** Send a pending invitation again, on a fresh link. */
    public function resendInvitation(string $invitation): RedirectResponse
    {
        $this->assertAdmin();

        $result = $this->attempt(ResendInvitation::class, ['organization_id' => $this->organizationId(), 'invitation_id' => $invitation]);

        if ($result instanceof ActionRefused) {
            return back()->with('error', $result->getMessage());
        }

        $sent = $result->value;

        return back()->with('status', 'Invitation sent again'.($sent instanceof SentInvitation ? ' to '.$sent->invitation->email : '').'.');
    }

    public function revokeInvitation(string $invitation): RedirectResponse
    {
        $this->assertAdmin();

        $result = $this->attempt(RevokeInvitation::class, ['organization_id' => $this->organizationId(), 'invitation_id' => $invitation]);

        if ($result instanceof ActionRefused) {
            return back()->with('error', $result->getMessage());
        }

        return back()->with('status', 'Invitation revoked. That link no longer works.');
    }

    /**
     * Owner is not a choice (the parse refuses it — ownership is transferred), and only an
     * owner may act on an existing owner: an admin cannot demote the owner
     * ({@see ChangeMemberRole}, from inside the organization).
     */
    public function changeRole(Request $request, string $member): RedirectResponse
    {
        $this->assertAdmin();

        // Untrusted: an unassignable or unknown role is refused outright rather than coerced
        // to a default, and the refusal names the choices.
        $next = OrgRoles::parse($request->string('role')->toString());

        if ($next === null) {
            return back()->withErrors(['role' => OrgRoles::message()]);
        }

        $result = $this->act(ChangeMemberRole::class, [
            'organization_id' => $this->organizationId(),
            'user_id' => $member,
            'role' => $next->value,
        ], ['role' => 'role', 'user_id' => 'member'], 'role', ['last_owner' => 'The organization must keep at least one owner.']);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Built-in role updated.');
    }

    /**
     * Grant or revoke one access role for one member.
     *
     * AN EXPLICIT SET rather than a toggle: a retried request and the checkbox must not
     * disagree about which state was asked for. From in here only the roles this
     * organization's own administrators may hand out — never a staff role, which an
     * environment administrator grants from theirs — and segregation of duties refuses a
     * toxic pair ({@see GrantMemberRole}).
     */
    public function setAccessRole(Request $request, string $member): RedirectResponse
    {
        $this->assertAdmin();

        $input = ['organization_id' => $this->organizationId(), 'user_id' => $member, 'role_id' => $request->string('role')->toString()];

        if (! $request->boolean('granted')) {
            $result = $this->act(RevokeMemberRole::class, $input, ['role_id' => 'role', 'user_id' => 'member'], 'role');

            return $result instanceof RedirectResponse ? $result : back()->with('status', 'Role revoked.');
        }

        $result = $this->act(GrantMemberRole::class, $input, ['role_id' => 'role', 'user_id' => 'member'], 'role');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Role granted.');
    }

    /**
     * Your own membership ends with "Leave organization", which says what it does and
     * refuses the last owner with the way out — not with a removal that looks like somebody
     * else's. Only an owner removes an owner ({@see RemoveMember}, from inside).
     */
    public function remove(string $member): RedirectResponse
    {
        $this->assertAdmin();

        $result = $this->act(RemoveMember::class, [
            'organization_id' => $this->organizationId(),
            'user_id' => $member,
        ], ['user_id' => 'member'], 'member', ['last_owner' => 'The organization must keep at least one owner.']);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Member removed.');
    }

    /**
     * Hand the organization to another member — its owner only ({@see TransferOwnership},
     * from inside). They become the owner and the current owner stays on as an admin.
     */
    public function transferOwnership(string $member, Subjects $subjects): RedirectResponse
    {
        $this->assertAdmin();

        $result = $this->act(TransferOwnership::class, ['id' => $this->organizationId(), 'user_id' => $member], ['user_id' => 'member'], 'member');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        // Already theirs: nothing happened, and there is nothing to say.
        if ($member === app(CurrentUser::class)->id()) {
            return back();
        }

        $subject = $subjects->find($member);

        return back()->with('status', 'Ownership transferred to '.($subject->name ?? $subject->email ?? 'that member').'. You are now an admin.');
    }

    /**
     * Leave this organization. Anybody may, except its last owner — who is told how to hand
     * it over or close it rather than being left with an organization nobody owns. The
     * person's own act ({@see LeaveOrganization}); where they land afterwards is this
     * console's.
     */
    public function leave(Request $request, AfterLeaving $after): RedirectResponse
    {
        $me = app(CurrentUser::class);
        $organizationId = $this->organizationId();
        $name = $me->organization()->name ?? 'the organization';

        $result = $this->act(LeaveOrganization::class, ['organization_id' => $organizationId], ['organization_id' => 'member'], 'member');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return $after->land($request, $me->id(), $organizationId, 'You left '.$name.'.');
    }

    private function assertAdmin(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }

    private function organizationId(): string
    {
        return app(CurrentUser::class)->organizationId() ?? '';
    }

    /**
     * The access roles this organization's own administrators may hand out — never a
     * staff-only role ({@see OrgAccessRoles::tenantAssignable()}). This page is the
     * tenant plane; an environment administrator grants staff rights from theirs.
     *
     * @return Collection<int, Role>
     */
    private function assignableRoles(string $organizationId)
    {
        return app(OrgAccessRoles::class)->tenantAssignable($organizationId);
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @return array<string, string>
     */
    private function appNames($roles): array
    {
        $names = [];

        $clients = Client::query()
            ->whereIn('client_id', $roles->pluck('client_id')->filter()->unique()->all())
            ->get(['client_id', 'name']);

        foreach ($clients as $client) {
            $names[(string) $client->client_id] = (string) $client->name;
        }

        return $names;
    }

    /**
     * What each role actually lets a member do — the "effective access across apps" view the
     * manage drawer shows, so a checkbox is not a word with no consequence attached.
     *
     * @param  Collection<int, Role>  $roles
     * @return array<string, list<string>>
     */
    private function permissionsByRole($roles): array
    {
        $rows = DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->whereIn('role_permission.role_id', $roles->pluck('id')->all())
            ->orderBy('permissions.name')
            ->get(['role_permission.role_id', 'permissions.name']);

        $out = [];

        foreach ($rows as $row) {
            $roleId = $row->role_id;
            $name = $row->name;

            // Narrowed rather than cast: the query builder answers `mixed`, and a cast here
            // would turn a schema change into a silently wrong string instead of a failure.
            if (is_string($roleId) && is_string($name)) {
                $out[$roleId][] = $name;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $userIds
     * @return array<string, list<string>>
     */
    private function assignmentsByUser(string $organizationId, array $userIds): array
    {
        $rows = RoleAssignment::query()
            ->where('organization_id', $organizationId)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'role_id']);

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->user_id][] = (string) $row->role_id;
        }

        return $out;
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @param  array<string, string>  $appNames
     * @param  array<string, list<string>>  $permissions
     * @return list<array<string, mixed>>
     */
    private function accessRoleProps($roles, array $appNames, array $permissions): array
    {
        $rows = [];

        foreach ($roles as $role) {
            $clientId = $role->client_id;

            $rows[] = [
                'id' => $role->id,
                'name' => $role->name,
                'key' => $role->key ?? 'org',
                // Grouped org-wide vs per-app: "what a person can do" reads differently
                // depending on which apps it reaches.
                'group' => $clientId === null ? 'Custom roles' : ($appNames[$clientId] ?? $clientId),
                'permissions' => $permissions[$role->id] ?? [],
            ];
        }

        return $rows;
    }
}
