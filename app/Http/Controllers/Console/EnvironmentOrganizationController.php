<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Invitations\ResendInvitation;
use App\Actions\Invitations\RevokeInvitation;
use App\Actions\Invitations\SendInvitation;
use App\Actions\Members\AddMember;
use App\Actions\Members\ChangeMemberRole;
use App\Actions\Members\GrantMemberRole;
use App\Actions\Members\RemoveMember;
use App\Actions\Members\RevokeMemberRole;
use App\Actions\Organizations\AddOrganizationDomain;
use App\Actions\Organizations\CreateOrganization;
use App\Actions\Organizations\DeleteOrganization;
use App\Actions\Organizations\ReactivateOrganization;
use App\Actions\Organizations\RemoveOrganizationDomain;
use App\Actions\Organizations\SetDomainCapture;
use App\Actions\Organizations\SuspendOrganization;
use App\Actions\Organizations\TransferOwnership;
use App\Actions\Organizations\UpdateOrganization;
use App\Actions\Organizations\VerifyOrganizationDomain;
use App\Http\Props\Shared\AppApiKeyRows;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Props\Shared\PendingInvitationProps;
use App\Http\Props\Shared\ReturnAppProps;
use App\Http\Props\Shared\RoleOptionProps;
use App\Http\Props\Shared\SupportSessionProps;
use App\Http\Requests\Console\AddOrganizationDomainRequest;
use App\Http\Requests\Console\AddOrganizationMemberRequest;
use App\Http\Requests\Console\InviteOrganizationMemberRequest;
use App\Http\Requests\Console\SaveOrganizationRequest;
use App\Http\Requests\Console\StoreOrganizationRequest;
use App\Platform\Actions\ActionRefused;
use App\Platform\ApiKeys\MemberApiKeys;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Help\HelpTopic;
use App\Platform\Invitations\AppReturnTargets;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\ValueObjects\PendingInvitationSummary;
use App\Platform\Invitations\ValueObjects\SentInvitation;
use App\Platform\OrgAccessRoles;
use App\Platform\OrgRoles;
use App\Platform\SupportAccess\Contracts\SupportAccess;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\CustomerApiKey;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Response;

/**
 * ENVIRONMENT PLANE › ORGANIZATIONS — the tenants inside this environment, and everything
 * about one of them: its details, its roster, its pending invitations and its claimed
 * email domains.
 *
 * THE WORD DOES TWO JOBS IN THIS PLATFORM, and the page says so rather than leaving a
 * reader to work out which altitude they are at: up in the platform root an "organization"
 * is a CUSTOMER OF CBOX ID, and here it is one of that customer's own end-user teams. They
 * look identical on screen because underneath they are the same kind of row.
 *
 * EVERY MUTATION RE-RESOLVES THE ORGANIZATION from the URL rather than trusting the page
 * that rendered the button, and every id a mutation is handed — a member, a role, a
 * domain — is checked against THAT organization before it is used. A page like this is
 * where a missed check is a cross-tenant write.
 *
 * Every mutation is an ACTION (app/Actions/Organizations, Members, Invitations) — the same
 * change, rules and audit entry the management API and MCP make — and those checks live
 * there, once, for every door. The organization is always passed explicitly, from the URL.
 */
final readonly class EnvironmentOrganizationController extends ConsoleController
{
    /** A page of the roster: the widest end-user surface in this console. */
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->assertEnvironmentAdmin();

        // Soft-deleted tenants are gone from every list: `Deleted` refuses their members
        // at the request pipeline, so a row here would be one nothing behind it honours.
        $query = Organization::query()
            ->where('status', '!=', OrganizationStatus::Deleted->value)
            ->orderBy('name');

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            $query->where(fn (Builder $q): Builder => $q
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('slug', 'like', '%'.$term.'%'));
        }

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        return $this->page('environment/organizations/index', 'Organizations', [
            'help' => HelpProps::for(HelpTopic::Organizations),
            'organizations' => array_map(static fn (Organization $organization): array => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status->value,
                'href' => route('environment.organizations.show', $organization->id),
            ], $page->getCollection()->all()),
            'pagination' => PaginationProps::from($page),
            'search' => $term,
            'createHref' => route('environment.organizations.create'),
        ]);
    }

    public function create(): Response
    {
        $this->assertEnvironmentAdmin();

        return $this->page('environment/organizations/create', 'New organization', [
            'indexHref' => route('environment.organizations'),
            'storeHref' => route('environment.organizations.store'),
        ]);
    }

    public function store(StoreOrganizationRequest $request): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        // A handle somebody typed is theirs, and a taken one is refused by name; left blank,
        // the action walks the name to the first free one.
        $slug = $request->slug() === null ? null : Str::slug($request->slug());

        $result = $this->act(CreateOrganization::class, [
            'name' => $request->name(),
            'slug' => $slug === '' ? null : $slug,
            'metadata' => $request->metadata(),
        ], ['name' => 'name', 'slug' => 'slug', 'metadata' => 'metadata'], 'name');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var Organization $organization */
        $organization = $result->value;

        return to_route('environment.organizations.show', $organization->id)
            ->with('status', 'Organization created.');
    }

    public function show(string $organization, Memberships $memberships, OrgAccessRoles $catalog, OrganizationInvitations $invitations, AppReturnTargets $targets, MemberApiKeys $apiKeys, AppApiKeyRows $apiKeyRows, SupportAccess $support): Response
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($organization);

        /*
         * A PAGE OF THE ROSTER, and the names looked up for JUST that page.
         *
         * This read was one row per member of the organization, hydrated in full on every
         * interaction — and the name lookup beside it was every user in the ENVIRONMENT.
         * Scoping both keeps the cost flat in the environment and proportional only to
         * what is on screen.
         */
        $roster = $memberships->paginateForOrganization($model->id, self::PER_PAGE);

        /** @var list<string> $memberIds */
        $memberIds = array_map(
            static fn (Membership $membership): string => (string) $membership->user_id,
            $roster->items(),
        );

        $users = User::query()->whereIn('id', $memberIds)->get(['id', 'name', 'email'])->keyBy('id');

        $accessRoles = $catalog->assignable($model->id);
        $appNames = $catalog->appNames($accessRoles);
        $inviteRoles = $catalog->tenantAssignable($model->id);
        $assignments = $catalog->assignmentsByUser($model->id, $memberIds);

        return $this->page('environment/organizations/show', $model->name, [
            'organization' => [
                'id' => $model->id,
                'name' => $model->name,
                'slug' => $model->slug,
                'status' => $model->status->value,
                'metadata' => $this->metadataOf($model),
            ],
            'members' => array_map(function (Membership $membership) use ($users, $assignments, $model): array {
                $user = $users->get($membership->user_id);

                return [
                    'userId' => (string) $membership->user_id,
                    'name' => $user->name ?? $user->email ?? (string) $membership->user_id,
                    'email' => $user?->email,
                    'role' => $membership->role->value,
                    'accessRoleIds' => array_values(array_filter(
                        (array) ($assignments[$membership->user_id] ?? []),
                        'is_string',
                    )),
                    'urls' => [
                        'role' => route('environment.organizations.members.role', [$model->id, $membership->user_id]),
                        'accessRole' => route('environment.organizations.members.access', [$model->id, $membership->user_id]),
                        'remove' => route('environment.organizations.members.remove', [$model->id, $membership->user_id]),
                        'transfer' => route('environment.organizations.members.transfer-ownership', [$model->id, $membership->user_id]),
                    ],
                ];
            }, $roster->items()),
            'pagination' => PaginationProps::from($roster),
            'invitations' => array_map(
                static fn (PendingInvitationSummary $pending): PendingInvitationProps => PendingInvitationProps::from(
                    $pending,
                    route('environment.organizations.invitations.resend', [$model->id, $pending->id]),
                    route('environment.organizations.invitations.revoke', [$model->id, $pending->id]),
                ),
                $invitations->pending($model->id),
            ),
            'domains' => $this->domainProps($model->id),
            'accessRoles' => $this->accessRoleProps($accessRoles, $appNames),
            // What an INVITATION may carry: the tenant plane's set, because accepting one
            // is the invitee's act inside the organization. Staff-only roles are granted
            // on the member once they have joined, from `accessRoles` above.
            'inviteAccessRoles' => $this->accessRoleProps($inviteRoles, $catalog->appNames($inviteRoles)),
            // The same lists the organization's own People page offers — one set of roles,
            // wherever somebody is invited from.
            'roleOptions' => RoleOptionProps::organization(),
            'rosterRoleOptions' => RoleOptionProps::organization(withOwner: true),
            'apps' => array_map(ReturnAppProps::from(...), $targets->appsFor($model->id)),
            // Every API key the organization's people hold for its apps. Seen and revoked
            // here; never minted — each person creates their own under My account.
            'apiKeys' => $apiKeyRows->for(
                $apiKeys->inOrganization($model->id),
                withHolder: true,
                revokeHref: static fn (CustomerApiKey $key): string => route('environment.organizations.api-keys.revoke', [$model->id, $key->id]),
            ),
            // Somebody signed in to an app as one of this organization's people, right now.
            // The organization's own activity log records each one; this is where the
            // environment's administrators see them while they are open, and end them.
            'supportSessions' => SupportSessionProps::list($support->activeForOrganization($model->id)),
            'indexHref' => route('environment.organizations'),
            'urls' => [
                'update' => route('environment.organizations.update', $model->id),
                'suspend' => route('environment.organizations.suspend', $model->id),
                'reactivate' => route('environment.organizations.reactivate', $model->id),
                'destroy' => route('environment.organizations.destroy', $model->id),
                'addMember' => route('environment.organizations.members.store', $model->id),
                'invite' => route('environment.organizations.invitations.store', $model->id),
                'addDomain' => route('environment.organizations.domains.store', $model->id),
            ],
        ]);
    }

    /**
     * Name, handle and metadata through the action — which renames through the framework
     * (announced as `organization.updated`, the old name kept on the trail) where this used
     * to save the model and record nothing. Anything else under `settings` belongs to
     * another screen and survives.
     */
    public function update(SaveOrganizationRequest $request, string $organization): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(UpdateOrganization::class, [
            'id' => $organization,
            'name' => $request->name(),
            'slug' => Str::slug($request->slug()),
            'metadata' => $request->metadata(),
        ], ['name' => 'name', 'slug' => 'slug', 'metadata' => 'metadata'], 'name', [
            'slug_taken' => 'That URL handle is already used by another organization.',
        ]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Organization updated.');
    }

    public function suspend(string $organization): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(SuspendOrganization::class, ['id' => $organization]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Organization suspended.');
    }

    public function reactivate(string $organization): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(ReactivateOrganization::class, ['id' => $organization]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Organization reactivated.');
    }

    /**
     * Soft-delete the tenant: status → Deleted, which takes it out of every list AND refuses
     * its members at the request pipeline, the device flow and the consent screen, exactly as
     * a suspension does — {@see Organizations::archive()}, through the action.
     */
    public function destroy(string $organization): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(DeleteOrganization::class, ['id' => $organization]);

        return $result instanceof RedirectResponse
            ? $result
            : to_route('environment.organizations')->with('status', 'Organization deleted.');
    }

    /**
     * Add an existing user by address, with the access roles ticked — one action, so a
     * segregation-of-duties conflict refuses the lot rather than leaving half of it behind.
     */
    public function addMember(AddOrganizationMemberRequest $request, string $organization): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(AddMember::class, [
            'organization_id' => $organization,
            'email' => $request->email(),
            'role' => $request->role()->value,
            'roles' => $request->accessRoleIds(),
        ], ['email' => 'email', 'user_id' => 'email', 'role' => 'role', 'roles' => 'accessRoles', 'organization_id' => 'email'], 'email', [
            'already_member' => 'That user is already a member.',
        ]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        if ($result->status === 200) {
            return back()->withInput()->withErrors(['email' => 'That user is already a member.']);
        }

        return back()->with('status', 'Member added.');
    }

    public function changeMemberRole(Request $request, string $organization, string $member): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        // Untrusted: an unassignable or unknown role is refused outright rather than
        // coerced to a default — and the refusal NAMES THE CHOICES, the same sentence the
        // add and invite forms give, so a stale tab gets an answer instead of a page that
        // silently did nothing.
        $next = OrgRoles::parse($request->string('role')->toString());

        if ($next === null) {
            return back()->withErrors(['role' => OrgRoles::message()]);
        }

        $result = $this->act(ChangeMemberRole::class, [
            'organization_id' => $organization,
            'user_id' => $member,
            'role' => $next->value,
        ], ['role' => 'role'], 'role', ['last_owner' => 'An organization must keep at least one owner.']);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Built-in role updated.');
    }

    /**
     * Grant or revoke one RBAC access-role for a member.
     *
     * AN EXPLICIT SET rather than a toggle: the row's checkbox and a retried request must
     * not disagree about which state was asked for. One action per state; segregation of
     * duties refuses a toxic pair and the refusal names both roles.
     */
    public function setAccessRole(Request $request, string $organization, string $member): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $request->validate([
            'role' => ['required', 'string'],
            'granted' => ['required', 'boolean'],
        ]);

        $input = ['organization_id' => $organization, 'user_id' => $member, 'role_id' => $request->string('role')->toString()];

        if (! $request->boolean('granted')) {
            $result = $this->act(RevokeMemberRole::class, $input, ['role_id' => 'accessRole'], 'accessRole');

            return $result instanceof RedirectResponse ? $result : back()->with('status', 'Access revoked.');
        }

        $result = $this->act(GrantMemberRole::class, $input, ['role_id' => 'accessRole'], 'accessRole');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Access granted.');
    }

    public function removeMember(string $organization, string $member): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(RemoveMember::class, [
            'organization_id' => $organization,
            'user_id' => $member,
        ], ['user_id' => 'member'], 'member', ['last_owner' => 'An organization must keep at least one owner.']);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Member removed.');
    }

    /**
     * The invitee accepts via the emailed token — nobody is added without consent. The same
     * action an app's backend sends one with, signed with this administrator's name.
     */
    public function invite(InviteOrganizationMemberRequest $request, string $organization): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(SendInvitation::class, [
            'organization_id' => $organization,
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

    public function resendInvitation(string $organization, string $invitation): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->attempt(ResendInvitation::class, ['organization_id' => $organization, 'invitation_id' => $invitation]);

        if ($result instanceof ActionRefused) {
            return back()->with('error', $result->getMessage());
        }

        $sent = $result->value;

        return back()->with('status', 'Invitation sent again'.($sent instanceof SentInvitation ? ' to '.$sent->invitation->email : '').'.');
    }

    public function revokeInvitation(string $organization, string $invitation): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->attempt(RevokeInvitation::class, ['organization_id' => $organization, 'invitation_id' => $invitation]);

        if ($result instanceof ActionRefused) {
            return back()->with('error', $result->getMessage());
        }

        return back()->with('status', 'Invitation revoked. That link no longer works.');
    }

    /**
     * Make a member the organization's owner.
     *
     * From OUTSIDE the organization, so there is no outgoing owner to name: every current
     * owner steps down to admin. That is also how an organization this console created —
     * which starts with no owner at all — gets its first one.
     */
    public function transferOwnership(string $organization, string $member): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(TransferOwnership::class, ['id' => $organization, 'user_id' => $member], ['user_id' => 'member'], 'member');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Ownership transferred.');
    }

    public function addDomain(AddOrganizationDomainRequest $request, string $organization): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(AddOrganizationDomain::class, [
            'organization_id' => $organization,
            'domain' => $request->domain(),
        ], ['domain' => 'domain'], 'domain');

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', 'Domain added — add the DNS TXT record shown below, then verify.');
    }

    public function verifyDomain(string $organization, string $domain): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(VerifyOrganizationDomain::class, ['organization_id' => $organization, 'domain_id' => $domain], [], 'domain');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Domain verified.');
    }

    /**
     * Capture routes everyone on this email domain to the organization's SSO connection, so
     * turning it on is refused for a domain nobody proved they own. The page's button flips
     * it; the action is told which state it means.
     */
    public function toggleCapture(string $organization, string $domain): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->ownedDomain($organization, $domain);

        $result = $this->act(SetDomainCapture::class, [
            'organization_id' => $model->organization_id,
            'domain_id' => $model->id,
            'enabled' => ! $model->capture,
        ], [], 'domain');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Domain capture updated.');
    }

    public function removeDomain(string $organization, string $domain): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(RemoveOrganizationDomain::class, ['organization_id' => $organization, 'domain_id' => $domain], [], 'domain');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Domain removed.');
    }

    /**
     * The domains this organization claims.
     *
     * The TXT record is `verification_token` — the ONE value somebody has to copy into a
     * DNS panel, so it is handed over as its own field rather than left in prose.
     *
     * @return list<array{id: string, domain: string, verified: bool, capture: bool, token: string, urls: array{verify: string, capture: string, remove: string}}>
     */
    private function domainProps(string $organizationId): array
    {
        $rows = [];

        foreach (app(DomainVerification::class)->forOrganization($organizationId) as $domain) {
            $rows[] = [
                'id' => $domain->id,
                'domain' => $domain->domain,
                'verified' => $domain->isVerified(),
                'capture' => $domain->capture,
                'token' => $domain->verification_token,
                'urls' => [
                    'verify' => route('environment.organizations.domains.verify', [$organizationId, $domain->id]),
                    'capture' => route('environment.organizations.domains.capture', [$organizationId, $domain->id]),
                    'remove' => route('environment.organizations.domains.remove', [$organizationId, $domain->id]),
                ],
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @param  array<string, string>  $appNames
     * @return list<array{id: string, name: string, app: string|null}>
     */
    private function accessRoleProps($roles, array $appNames): array
    {
        $rows = [];

        foreach ($roles as $role) {
            $rows[] = [
                'id' => $role->id,
                'name' => $role->name,
                // Grouped org-wide vs per-app, because "what a person can do" reads
                // differently depending on which apps it reaches.
                'app' => $role->client_id === null ? null : ($appNames[$role->client_id] ?? $role->client_id),
                // A staff role the environment may grant inside one organization; tagged so
                // it is not mistaken for one of the organization's own. Tenant surfaces
                // never list it (OrgAccessRoles::tenantAssignable()).
                'staffOnly' => $role->tenant_assignable === false,
            ];
        }

        return $rows;
    }

    /** The environment console's own gate: a membership administering THIS environment. */
    private function assertEnvironmentAdmin(): void
    {
        abort_if(app(EnvironmentAdminAuth::class)->membership() === null, 403);
    }

    private function resolve(string $organization): Organization
    {
        $model = Organization::query()->whereKey($organization)->first();

        abort_if($model === null, 404);

        return $model;
    }

    /**
     * A domain, checked against THIS organization before anything is done to it.
     *
     * Resolved rather than checked afterwards: the id arrives in the URL, and a verify or a
     * capture toggle on somebody else's claimed domain is a cross-tenant write.
     */
    private function ownedDomain(string $organization, string $domain): VerifiedDomain
    {
        $model = VerifiedDomain::query()
            ->whereKey($domain)
            ->where('organization_id', $this->resolve($organization)->id)
            ->first();

        abort_if($model === null, 404);

        return $model;
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function metadataOf(Organization $organization): array
    {
        $meta = $organization->settings['metadata'] ?? [];

        if (! is_array($meta)) {
            return [];
        }

        $rows = [];

        foreach ($meta as $key => $value) {
            $rows[] = [
                'key' => (string) $key,
                'value' => is_scalar($value) ? (string) $value : '',
            ];
        }

        return $rows;
    }
}
