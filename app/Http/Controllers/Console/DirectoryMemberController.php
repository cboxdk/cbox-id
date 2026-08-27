<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\InviteDirectoryMemberRequest;
use App\Mail\InvitationMail;
use App\Models\InvitationRoleGrant;
use App\Platform\CurrentUser;
use App\Platform\GrantAccessRole;
use App\Platform\Help\HelpTopic;
use App\Platform\MailLinks;
use App\Platform\OrgAccessRoles;
use App\Platform\OrgRoles;
use App\Platform\SodGuard;
use Carbon\CarbonInterface;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Invitations;
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
use Illuminate\Support\Facades\Mail;
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
    public function index(Memberships $memberships, Subjects $subjects, Invitations $invitations): Response
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
                ],
            ];
        }

        return $this->page('console/directory-members', 'Members', [
            'isAdmin' => $me->isAdmin(),
            'members' => $rows,
            'pagination' => PaginationProps::from($page),
            // Only an admin sees the pending list at all: an invitation names an address
            // somebody chose to invite, which is not a plain member's business.
            'invitations' => $me->isAdmin() ? $this->invitationProps($invitations, $organizationId) : [],
            'accessRoles' => $this->accessRoleProps($accessRoles, $appNames, $permissions),
            'assignableRoles' => array_map(static fn (MembershipRole $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
                // Only an owner may invite somebody straight to owner, or promote one.
                'ownerOnly' => $role === MembershipRole::Owner,
            ], OrgRoles::assignable()),
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
            'help' => HelpProps::for(HelpTopic::Members),
        ]);
    }

    public function invite(
        InviteDirectoryMemberRequest $request,
        Invitations $invitations,
        MailLinks $links,
    ): RedirectResponse {
        $this->assertAdmin();

        $me = app(CurrentUser::class);
        $organizationId = $this->organizationId();

        // Only an owner may invite somebody straight to owner.
        abort_if($request->role() === MembershipRole::Owner && ! $me->isOwner(), 403);

        /*
         * THE PARKED ACCESS ROLES ARE A GRANT LIKE ANY OTHER, just deferred — so segregation
         * of duties is checked HERE, where there is a form to report into. By acceptance
         * time the only place left to refuse is a redirect-only controller with nowhere to
         * say why.
         */
        $selected = array_values(array_intersect(
            $request->accessRoleIds(),
            $this->assignableRoleIds($organizationId),
        ));

        $refusal = app(SodGuard::class)->refuseSet($organizationId, $selected);

        if ($refusal !== null) {
            return back()->withInput()->withErrors(['accessRoles' => $refusal->message()]);
        }

        // A PENDING invitation — membership is granted only when the invitee accepts the
        // emailed token. Nobody is added without consent.
        $pending = $invitations->invite($organizationId, $request->email(), $request->role(), invitedBy: $me->id());

        Mail::to($request->email())->send(new InvitationMail(
            organization: $me->organization()->name ?? 'your team',
            inviter: $me->name(),
            url: $links->route('invitation.accept', $pending->token),
        ));

        foreach ($selected as $roleId) {
            /*
             * KEYED TO THIS INVITATION, not to the address. Parked by (org, email) alone, a
             * grant outlived the invitation that chose it: revoke the invite and the roles
             * stayed, waiting for the next invitation to that address to pick them up.
             */
            InvitationRoleGrant::query()->firstOrCreate([
                'invitation_id' => $pending->invitation->id,
                'role_id' => $roleId,
            ], [
                'organization_id' => $organizationId,
                'email' => $request->email(),
            ]);
        }

        return back()->with('status', 'Invitation sent to '.$request->email().'.');
    }

    public function revokeInvitation(string $invitation, Invitations $invitations): RedirectResponse
    {
        $this->assertAdmin();

        $invitations->revoke($this->organizationId(), $invitation);

        // AND THE ROLES IT PARKED. Revoking used to update the invitation row and leave the
        // grants behind, so the roles just withdrawn sat waiting for the next invitation to
        // that address to collect them.
        InvitationRoleGrant::query()->where('invitation_id', $invitation)->delete();

        return back()->with('status', 'Invitation revoked.');
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

        // Only an owner may grant the owner role, and only an owner may act on an existing
        // owner — an admin cannot demote the organization's owner.
        abort_if($next === MembershipRole::Owner && ! $me->isOwner(), 403);
        abort_if($this->isOwner($member, $memberships) && ! $me->isOwner(), 403);

        if ($this->managedElsewhere()) {
            return back()->withErrors(['role' => self::MANAGED_ELSEWHERE]);
        }

        try {
            $memberships->changeRole($this->organizationId(), $member, $next);
        } catch (LastOwner) {
            return back()->withErrors(['role' => 'The organization must keep at least one owner.']);
        }

        return back()->with('status', 'Console access updated.');
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
        if ($memberships->of($organizationId, $member) === null || ! $catalog->isAssignable($organizationId, $roleId)) {
            return back();
        }

        if (! $request->boolean('granted')) {
            $roles->unassign($organizationId, $member, $roleId);

            return back()->with('status', 'Access role revoked.');
        }

        /*
         * Segregation of duties is a PRE-GRANT gate the host has to call — the contract says
         * so, and it is the whole published API. The console shipped the SoD screens and
         * never called it, so an admin could create on this page exactly the toxic
         * combination the governance page reports.
         */
        $refusal = app(GrantAccessRole::class)->grant($organizationId, $member, $roleId, GrantSource::Manual);

        if ($refusal !== null) {
            return back()->withErrors(['role' => $refusal->message()]);
        }

        return back()->with('status', 'Access role granted.');
    }

    public function remove(string $member, Memberships $memberships): RedirectResponse
    {
        $this->assertAdmin();

        $me = app(CurrentUser::class);

        if ($this->managedElsewhere()) {
            return back()->withErrors(['member' => self::MANAGED_ELSEWHERE]);
        }

        if ($member === $me->id()) {
            return back()->withErrors(['member' => 'You cannot remove yourself.']);
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

    /** The sentence a refused roster write gets, and the page's own banner. */
    private const MANAGED_ELSEWHERE = 'This organization is a customer of this platform. Manage its members under Identity platform → Members.';

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
     * The access roles a member may hold here: this organization's own org-wide roles, plus
     * the roles declared by apps this organization can use.
     *
     * @return Collection<int, Role>
     */
    private function assignableRoles(string $organizationId)
    {
        $clientIds = Client::query()
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->pluck('client_id');

        return Role::query()
            ->where(function ($q) use ($organizationId, $clientIds): void {
                $q->where(fn ($x) => $x->where('organization_id', $organizationId)->whereNull('client_id'))
                    ->orWhere(fn ($x) => $x->whereIn('client_id', $clientIds)->whereNull('orphaned_at'));
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<string>
     */
    private function assignableRoleIds(string $organizationId): array
    {
        return array_values(array_filter(
            $this->assignableRoles($organizationId)->pluck('id')->all(),
            'is_string',
        ));
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
                'group' => $clientId === null ? 'Org roles' : ($appNames[$clientId] ?? $clientId),
                'permissions' => $permissions[$role->id] ?? [],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invitationProps(Invitations $invitations, string $organizationId): array
    {
        $rows = [];

        foreach ($invitations->pending($organizationId) as $invitation) {
            $rows[] = [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->label(),
                'expires' => $invitation->expires_at->diffForHumans(),
                'revokeHref' => route('directory.members.invitations.revoke', $invitation->id),
            ];
        }

        return $rows;
    }
}
