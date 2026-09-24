<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Props\Shared\PendingInvitationProps;
use App\Http\Props\Shared\ReturnAppProps;
use App\Http\Props\Shared\RoleOptionProps;
use App\Http\Requests\Console\AddOrganizationDomainRequest;
use App\Http\Requests\Console\AddOrganizationMemberRequest;
use App\Http\Requests\Console\InviteOrganizationMemberRequest;
use App\Http\Requests\Console\SaveOrganizationRequest;
use App\Http\Requests\Console\StoreOrganizationRequest;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\GrantAccessRole;
use App\Platform\Help\HelpTopic;
use App\Platform\Invitations\AppReturnTargets;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\PendingInvitationSummary;
use App\Platform\Membership\MembershipLifecycle;
use App\Platform\Membership\MembershipRefused;
use App\Platform\OrgAccessRoles;
use App\Platform\OrgRoles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Exceptions\DomainAlreadyClaimed;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Exceptions\LastOwner;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
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

    public function store(StoreOrganizationRequest $request, Organizations $organizations): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $slug = $this->uniqueSlug($organizations, Str::slug($request->slug() ?? $request->name()));

        $settings = $request->metadata() === [] ? [] : ['metadata' => $request->metadata()];

        $organization = $organizations->create(new NewOrganization(
            name: $request->name(),
            slug: $slug,
            settings: $settings,
        ));

        return to_route('environment.organizations.show', $organization->id)
            ->with('status', 'Organization created.');
    }

    public function show(string $organization, Memberships $memberships, OrgAccessRoles $catalog, OrganizationInvitations $invitations, AppReturnTargets $targets): Response
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

    public function update(SaveOrganizationRequest $request, string $organization, Organizations $organizations): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($organization);
        $slug = Str::slug($request->slug());

        $existing = $organizations->bySlug($slug);

        if ($existing !== null && $existing->id !== $model->id) {
            return back()->withInput()->withErrors([
                'slug' => 'That URL handle is already used by another organization.',
            ]);
        }

        // Only the metadata subtree is edited here; anything else under `settings` belongs
        // to a different screen and must survive this save.
        $settings = $model->settings;
        $metadata = $request->metadata();

        if ($metadata === []) {
            unset($settings['metadata']);
        } else {
            $settings['metadata'] = $metadata;
        }

        $model->name = $request->name();
        $model->slug = $slug;
        $model->settings = $settings;
        $model->save();

        return back()->with('status', 'Organization updated.');
    }

    public function suspend(string $organization, Organizations $organizations): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $organizations->suspend($this->resolve($organization)->id, $this->actorId());

        return back()->with('status', 'Organization suspended.');
    }

    public function reactivate(string $organization, Organizations $organizations): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $organizations->reactivate($this->resolve($organization)->id, $this->actorId());

        return back()->with('status', 'Organization reactivated.');
    }

    /**
     * Soft-delete the tenant: status → Deleted, which takes it out of every list AND
     * refuses its members at the request pipeline, the device flow and the consent screen,
     * exactly as a suspension does.
     *
     * {@see Organizations::archive()}, the framework's verb for it — the same one suspend
     * and reactivate above use. This wrote the status onto the model and recorded its own
     * `organization.deleted` entry, which skipped what only the service does: forgetting
     * the organization's cached environment resolution, and announcing it to webhook
     * subscribers as `organization.deleted` (and the legacy `organization.archived`).
     */
    public function destroy(string $organization, Organizations $organizations): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $organizations->archive($this->resolve($organization)->id, $this->actorId());

        return to_route('environment.organizations')->with('status', 'Organization deleted.');
    }

    public function addMember(
        AddOrganizationMemberRequest $request,
        string $organization,
        Memberships $memberships,
        OrgAccessRoles $catalog,
    ): RedirectResponse {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($organization);
        $user = User::query()->where('email', $request->email())->first();

        if ($user === null) {
            return back()->withInput()->withErrors([
                'email' => 'No user with that email in this environment. Create the user first.',
            ]);
        }

        if ($memberships->of($model->id, $user->id) !== null) {
            return back()->withInput()->withErrors(['email' => 'That user is already a member.']);
        }

        /*
         * The membership is the "belongs to org" record; its tier governs org
         * administration and support-impersonation safety. What the person can DO in the
         * apps comes from the access roles below.
         */
        $memberships->add($model->id, $user->id, $request->role());

        $this->grantAccessRoles($model->id, $user->id, $request->accessRoleIds(), $catalog);

        return back()->with('status', 'Member added.');
    }

    public function changeMemberRole(Request $request, string $organization, string $member, Memberships $memberships): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($organization);

        // Untrusted: an unassignable or unknown role is refused outright rather than
        // coerced to a default — and the refusal NAMES THE CHOICES, the same sentence the
        // add and invite forms give, so a stale tab gets an answer instead of a page that
        // silently did nothing.
        $next = OrgRoles::parse($request->string('role')->toString());

        if ($next === null) {
            return back()->withErrors(['role' => OrgRoles::message()]);
        }

        if ($memberships->of($model->id, $member) === null) {
            return back();
        }

        try {
            $memberships->changeRole($model->id, $member, $next);
        } catch (LastOwner) {
            return back()->withErrors(['role' => 'An organization must keep at least one owner.']);
        }

        return back()->with('status', 'Built-in role updated.');
    }

    /**
     * Grant or revoke one RBAC access-role for a member.
     *
     * AN EXPLICIT SET rather than a toggle: the row's checkbox and a retried request must
     * not disagree about which state was asked for.
     */
    public function setAccessRole(
        Request $request,
        string $organization,
        string $member,
        Memberships $memberships,
        OrgAccessRoles $catalog,
    ): RedirectResponse {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($organization);

        $request->validate([
            'role' => ['required', 'string'],
            'granted' => ['required', 'boolean'],
        ]);

        $roleId = $request->string('role')->toString();

        // Only a real member of this org, and only a role genuinely assignable here.
        if ($memberships->of($model->id, $member) === null || ! $catalog->isAssignable($model->id, $roleId)) {
            return back();
        }

        if (! $request->boolean('granted')) {
            app(GrantAccessRole::class)->revoke($model->id, $member, $roleId);

            return back()->with('status', 'Access revoked.');
        }

        // Segregation of duties refuses a toxic pair here exactly as it does on the
        // Members page, and the refusal names both roles.
        $refusal = app(GrantAccessRole::class)->grant($model->id, $member, $roleId, GrantSource::Manual);

        if ($refusal !== null) {
            return back()->withErrors(['accessRole' => $refusal->message()]);
        }

        return back()->with('status', 'Access granted.');
    }

    public function removeMember(string $organization, string $member, Memberships $memberships): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($organization);

        if ($memberships->of($model->id, $member) === null) {
            return back();
        }

        try {
            $memberships->remove($model->id, $member);
        } catch (LastOwner) {
            return back()->withErrors(['member' => 'An organization must keep at least one owner.']);
        }

        return back()->with('status', 'Member removed.');
    }

    public function invite(
        InviteOrganizationMemberRequest $request,
        string $organization,
        OrganizationInvitations $invitations,
    ): RedirectResponse {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($organization);

        // The invitee accepts via the emailed token — nobody is added without consent. The
        // same service the organization's own People page uses, so an invitation from here
        // offers the same roles, parks access roles the same way and can be re-sent.
        try {
            $sent = $invitations->send($request->toInvitation($model->id, $this->inviter()));
        } catch (InvitationRefused $refused) {
            return back()->withInput()->withErrors([$refused->field() => $refused->getMessage()]);
        }

        return back()->with('status', 'Invitation sent to '.$sent->invitation->email.'.');
    }

    public function resendInvitation(string $organization, string $invitation, OrganizationInvitations $invitations): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        try {
            $sent = $invitations->resend($this->resolve($organization)->id, $invitation, $this->inviter());
        } catch (InvitationRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return back()->with('status', 'Invitation sent again to '.$sent->invitation->email.'.');
    }

    public function revokeInvitation(string $organization, string $invitation, OrganizationInvitations $invitations): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        try {
            $invitations->revoke($this->resolve($organization)->id, $invitation, $this->actorId());
        } catch (InvitationRefused $refused) {
            return back()->with('error', $refused->getMessage());
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
    public function transferOwnership(string $organization, string $member, MembershipLifecycle $lifecycle): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($organization);

        try {
            $lifecycle->transferOwnership($model->id, $member, null, $this->actorId());
        } catch (MembershipRefused $refused) {
            return back()->withErrors(['member' => $refused->getMessage()]);
        }

        return back()->with('status', 'Ownership transferred.');
    }

    public function addDomain(AddOrganizationDomainRequest $request, string $organization, DomainVerification $domains): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        try {
            $domains->add($this->resolve($organization)->id, $request->domain());
        } catch (DomainAlreadyClaimed) {
            return back()->withInput()->withErrors(['domain' => 'That domain is already claimed.']);
        }

        return back()->with('status', 'Domain added — add the DNS TXT record shown below, then verify.');
    }

    public function verifyDomain(string $organization, string $domain, DomainVerification $domains): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        if ($domains->verify($this->ownedDomain($organization, $domain)->id)) {
            return back()->with('status', 'Domain verified.');
        }

        return back()->withErrors([
            'domain' => 'Verification failed — the DNS TXT record was not found yet.',
        ]);
    }

    public function toggleCapture(string $organization, string $domain, DomainVerification $domains): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->ownedDomain($organization, $domain);

        /*
         * Capture routes everyone on this email domain to the organization's SSO
         * connection, so enabling it on an UNPROVEN domain lets an organization claim
         * addresses it does not own. The service asserts this too — this check stays
         * because a refusal a person can read beats an unhandled exception, and because
         * the service is the backstop for callers that forget.
         */
        if (! $model->capture && ! $model->isVerified()) {
            return back()->withErrors(['domain' => 'Verify the domain before turning capture on.']);
        }

        $domains->setCapture($model->id, ! $model->capture);

        return back()->with('status', 'Domain capture updated.');
    }

    public function removeDomain(string $organization, string $domain, DomainVerification $domains): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $domains->remove($this->ownedDomain($organization, $domain)->id);

        return back()->with('status', 'Domain removed.');
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
     * Grant the chosen access roles, ignoring any posted id that is not genuinely
     * assignable in this organization (deny-by-default). The assignable set is resolved
     * ONCE rather than re-queried per selected role.
     *
     * @param  list<string>  $roleIds
     */
    private function grantAccessRoles(string $organizationId, string $userId, array $roleIds, OrgAccessRoles $catalog): void
    {
        if ($roleIds === []) {
            return;
        }

        $assignable = $catalog->assignable($organizationId)->pluck('id')->all();

        foreach ($roleIds as $roleId) {
            if (in_array($roleId, $assignable, true)) {
                // A grant withheld by segregation of duties is not fatal: the rest of the
                // assignment stands and the governance screen reports what was not given.
                app(GrantAccessRole::class)->grant($organizationId, $userId, $roleId, GrantSource::Manual);
            }
        }
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

    private function uniqueSlug(Organizations $organizations, string $base): string
    {
        $base = $base !== '' ? $base : 'org';
        $slug = $base;
        $n = 2;

        while ($organizations->bySlug($slug) !== null) {
            $slug = $base.'-'.$n;
            $n++;
        }

        return $slug;
    }

    /**
     * WHO is acting — the SUBJECT id, which is the id the audit trail is keyed on.
     *
     * It used to be the member row's id, and briefly the MEMBERSHIP id: both are row ids in
     * a table that is not `users`, so an entry written here resolved against a different id
     * space than one written by the console.
     */
    private function actorId(): string
    {
        return app(EnvironmentAdminAuth::class)->subjectId() ?? '';
    }

    /**
     * Who is sending an invitation from here: the administrator's SUBJECT id, which the
     * trail is keyed on, and the name to sign it with.
     */
    private function inviter(): Inviter
    {
        $actorId = $this->actorId();

        return new Inviter($actorId === '' ? null : $actorId, $this->inviterName());
    }

    /**
     * The name to sign an invitation with — through the SUBJECT, because a membership
     * carries authority and not identity.
     */
    private function inviterName(): string
    {
        $subjectId = app(EnvironmentAdminAuth::class)->subjectId();

        $subject = $subjectId === null ? null : app(PlatformRoot::class)->run(
            fn () => app(Subjects::class)->find($subjectId),
        );

        if ($subject === null) {
            return 'An administrator';
        }

        return $subject->name ?? $subject->email ?? 'An administrator';
    }
}
