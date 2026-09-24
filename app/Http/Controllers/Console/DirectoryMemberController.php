<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Props\Shared\PendingInvitationProps;
use App\Http\Props\Shared\ReturnAppProps;
use App\Http\Props\Shared\RoleOptionProps;
use App\Http\Requests\Console\InviteOrganizationMemberRequest;
use App\Platform\CurrentUser;
use App\Platform\GrantAccessRole;
use App\Platform\Help\HelpTopic;
use App\Platform\Invitations\AppReturnTargets;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\PendingInvitationSummary;
use App\Platform\Membership\AfterLeaving;
use App\Platform\Membership\MembershipLifecycle;
use App\Platform\Membership\MembershipRefused;
use App\Platform\OrgAccessRoles;
use App\Platform\OrgRoles;
use Carbon\CarbonInterface;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Exceptions\LastOwner;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\PlatformRoot;
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

        $accessRoles = $this->assignableRoles($organizationId);
        $appNames = $this->appNames($accessRoles);
        $permissions = $this->permissionsByRole($accessRoles);
        $assignments = $this->assignmentsByUser($organizationId, $userIds);

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

        return $this->page('console/directory-members', 'Members', [
            'isAdmin' => $me->isAdmin(),
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
            'accessRoles' => $this->accessRoleProps($accessRoles, $appNames, $permissions),
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
            'managedElsewhere' => $this->governedByTheManagementConsole($organizationId),
            'rolesHref' => route('roles'),
            'inviteHref' => route('directory.members.invite'),
            'leaveHref' => route('directory.members.leave'),
            'organizationName' => $me->organization()->name ?? '',
            'help' => HelpProps::for(HelpTopic::Members),
        ]);
    }

    public function invite(InviteOrganizationMemberRequest $request, OrganizationInvitations $invitations): RedirectResponse
    {
        $this->assertAdmin();

        if ($this->managedElsewhere()) {
            return back()->withErrors(['email' => self::MANAGED_ELSEWHERE]);
        }

        // A PENDING invitation — membership is granted only when the invitee accepts the
        // emailed token. Nobody is added without consent. The rules (which roles, which
        // access roles, which app to return to) are the invitation service's, so this page
        // and the environment console cannot answer them differently.
        try {
            $sent = $invitations->send($request->toInvitation($this->organizationId(), $this->inviter()));
        } catch (InvitationRefused $refused) {
            return back()->withInput()->withErrors([$refused->field() => $refused->getMessage()]);
        }

        return back()->with('status', 'Invitation sent to '.$sent->invitation->email.'.');
    }

    /** Send a pending invitation again, on a fresh link. */
    public function resendInvitation(string $invitation, OrganizationInvitations $invitations): RedirectResponse
    {
        $this->assertAdmin();

        try {
            $sent = $invitations->resend($this->organizationId(), $invitation, $this->inviter());
        } catch (InvitationRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return back()->with('status', 'Invitation sent again to '.$sent->invitation->email.'.');
    }

    public function revokeInvitation(string $invitation, OrganizationInvitations $invitations): RedirectResponse
    {
        $this->assertAdmin();

        try {
            $invitations->revoke($this->organizationId(), $invitation, app(CurrentUser::class)->id());
        } catch (InvitationRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return back()->with('status', 'Invitation revoked. That link no longer works.');
    }

    public function changeRole(Request $request, string $member, Memberships $memberships): RedirectResponse
    {
        $this->assertAdmin();

        // Untrusted: an unassignable or unknown role is refused outright rather than coerced
        // to a default, and the refusal names the choices.
        $next = OrgRoles::parse($request->string('role')->toString());

        if ($next === null) {
            return back()->withErrors(['role' => OrgRoles::message()]);
        }

        $me = app(CurrentUser::class);

        // Owner is not a choice (the parse above refuses it — ownership is transferred), and
        // only an owner may act on an existing owner: an admin cannot demote the owner.
        abort_if($this->isOwner($member, $memberships) && ! $me->isOwner(), 403);

        if ($this->managedElsewhere()) {
            return back()->withErrors(['role' => self::MANAGED_ELSEWHERE]);
        }

        try {
            $memberships->changeRole($this->organizationId(), $member, $next);
        } catch (LastOwner) {
            return back()->withErrors(['role' => 'The organization must keep at least one owner.']);
        }

        return back()->with('status', 'Built-in role updated.');
    }

    /**
     * Grant or revoke one access role for one member.
     *
     * AN EXPLICIT SET rather than a toggle: a retried request and the checkbox must not
     * disagree about which state was asked for.
     */
    public function setAccessRole(
        Request $request,
        string $member,
        Roles $roles,
        Memberships $memberships,
        OrgAccessRoles $catalog,
    ): RedirectResponse {
        $this->assertAdmin();

        $organizationId = $this->organizationId();
        $roleId = $request->string('role')->toString();

        /*
         * SERVER-SIDE AUTHORIZATION, not just a hidden control: the target must be a real
         * member of THIS organization, and the role one genuinely assignable here — which
         * excludes another organization's private-app roles. The framework's role service is
         * the backstop; this pair is the gate.
         */
        if ($memberships->of($organizationId, $member) === null || ! $catalog->isTenantAssignable($organizationId, $roleId)) {
            return back();
        }

        if (! $request->boolean('granted')) {
            $roles->unassign($organizationId, $member, $roleId);

            return back()->with('status', 'Role revoked.');
        }

        /*
         * Segregation of duties is a PRE-GRANT gate the host has to call — the contract says
         * so, and it is the whole published API. The console shipped the SoD screens and
         * never called it, so an admin could create on this page exactly the toxic
         * combination the governance page reports.
         */
        $refusal = app(GrantAccessRole::class)->grantAsTenant($organizationId, $member, $roleId, GrantSource::Manual);

        if ($refusal !== null) {
            return back()->withErrors(['role' => $refusal->message()]);
        }

        return back()->with('status', 'Role granted.');
    }

    public function remove(string $member, Memberships $memberships): RedirectResponse
    {
        $this->assertAdmin();

        $me = app(CurrentUser::class);

        if ($this->managedElsewhere()) {
            return back()->withErrors(['member' => self::MANAGED_ELSEWHERE]);
        }

        // Your own membership ends with "Leave organization", which says what it does and
        // refuses the last owner with the way out — not with a removal that looks like
        // somebody else's.
        if ($member === $me->id()) {
            return back()->withErrors(['member' => 'To remove yourself, use "Leave organization".']);
        }

        // Only an owner may remove another owner.
        abort_if($this->isOwner($member, $memberships) && ! $me->isOwner(), 403);

        try {
            $memberships->remove($this->organizationId(), $member);
        } catch (LastOwner) {
            return back()->withErrors(['member' => 'The organization must keep at least one owner.']);
        }

        return back()->with('status', 'Member removed.');
    }

    /**
     * Hand the organization to another member — its owner only. They become the owner and
     * the current owner stays on as an admin.
     */
    public function transferOwnership(string $member, MembershipLifecycle $lifecycle, Subjects $subjects): RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->isOwner(), 403);

        if ($this->managedElsewhere()) {
            return back()->withErrors(['member' => self::MANAGED_ELSEWHERE]);
        }

        if ($member === $me->id()) {
            return back();
        }

        try {
            $lifecycle->transferOwnership($this->organizationId(), $member, $me->id(), $me->id());
        } catch (MembershipRefused $refused) {
            return back()->withErrors(['member' => $refused->getMessage()]);
        }

        $subject = $subjects->find($member);

        return back()->with('status', 'Ownership transferred to '.($subject->name ?? $subject->email ?? 'that member').'. You are now an admin.');
    }

    /**
     * Leave this organization. Anybody may, except its last owner — who is told how to hand
     * it over or close it rather than being left with an organization nobody owns.
     */
    public function leave(Request $request, MembershipLifecycle $lifecycle, AfterLeaving $after): RedirectResponse
    {
        $me = app(CurrentUser::class);
        $organizationId = $this->organizationId();

        if ($this->managedElsewhere()) {
            return back()->withErrors(['member' => self::MANAGED_ELSEWHERE]);
        }

        try {
            $lifecycle->leave($organizationId, $me->id());
        } catch (MembershipRefused $refused) {
            return back()->withErrors(['member' => $refused->getMessage()]);
        }

        return $after->land($request, $me->id(), $organizationId, 'You left '.($me->organization()->name ?? 'the organization').'.');
    }

    /** Who is sending an invitation: the subject the trail is keyed on, and their name. */
    private function inviter(): Inviter
    {
        $me = app(CurrentUser::class);

        return new Inviter($me->id(), $me->name());
    }

    /** The sentence a refused roster write gets, and the page's own banner. */
    private const MANAGED_ELSEWHERE = 'This organization is a Cbox workspace. Its team is managed under Workspace › Team.';

    private function assertAdmin(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }

    private function organizationId(): string
    {
        return app(CurrentUser::class)->organizationId() ?? '';
    }

    private function isOwner(string $userId, Memberships $memberships): bool
    {
        return $memberships->of($this->organizationId(), $userId)?->role === MembershipRole::Owner;
    }

    private function managedElsewhere(): bool
    {
        return $this->governedByTheManagementConsole($this->organizationId());
    }

    /**
     * Whether the acting organization's roster belongs to the MANAGEMENT console rather than
     * to this page.
     *
     * THE ORIGINAL REASON IS GONE, and the replacement is narrower — worth stating plainly so
     * nobody restores the old one. This used to ask whether a person's place was governed by
     * an ACCOUNT membership, because there were two writers of one person's role. There is
     * ONE row now: both consoles write `memberships.role`, so they cannot disagree, and that
     * half of the argument retires with the plane it described.
     *
     * What remains is a boundary rather than a consistency problem: an organization that owns
     * PRODUCTS is a customer, and a customer's roster is administered by somebody holding an
     * organization capability — not from a console whose authority is "administers this one
     * environment". An operator pointing that console at the platform root would otherwise be
     * able to re-role a customer's owner from a page that never asked whether they may.
     *
     * Asked of the ORGANIZATION, not of the person: every member of a customer is covered,
     * including one added after this check was written.
     */
    private function governedByTheManagementConsole(string $organizationId): bool
    {
        return app(PlatformRoot::class)->run(
            fn (): bool => app(OrganizationProjects::class)->forOrganization($organizationId)->isNotEmpty(),
        ) === true;
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
