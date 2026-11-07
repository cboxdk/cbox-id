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
use App\Http\Middleware\BindConsoleOrganization;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\AddOrganizationDomainRequest;
use App\Http\Requests\Console\AddOrganizationMemberRequest;
use App\Http\Requests\Console\InviteOrganizationMemberRequest;
use App\Http\Requests\Console\SaveOrganizationRequest;
use App\Http\Requests\Console\StoreOrganizationRequest;
use App\Platform\Actions\ActionRefused;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Help\HelpTopic;
use App\Platform\Invitations\ValueObjects\SentInvitation;
use App\Platform\OrgAccessRoles;
use App\Platform\OrgRoles;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;

/**
 * ENVIRONMENT PLANE › ORGANIZATIONS — the tenants inside this environment: the list, the
 * form that creates one, and every write about one of them — its details, its roster, its
 * pending invitations and its claimed email domains. The PAGES about one organization are
 * its hub's tabs (app/Http/Controllers/Console/Organization), one URL each.
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
        abort_unless(app(EnvironmentAdminAuth::class)->canRead(), 403);

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

        return $this->page('environment/organizations/index', __('console.organizations_2730183d'), [
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

        return $this->page('environment/organizations/create', __('console.new_organization_62e34b70'), [
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
            ->with('status', __('console.organization_created_abe7549f'));
    }

    /**
     * Name, handle and metadata through the action — which renames through the framework
     * (announced as `organization.updated`, the old name kept on the trail) where this used
     * to save the model and record nothing. Anything else under `settings` belongs to
     * another screen and survives.
     */
    public function update(SaveOrganizationRequest $request): RedirectResponse
    {
        $organization = $this->organizationInUrl();

        $this->assertEnvironmentAdmin();

        $result = $this->act(UpdateOrganization::class, [
            'id' => $organization,
            'name' => $request->name(),
            'slug' => Str::slug($request->slug()),
            'metadata' => $request->metadata(),
        ], ['name' => 'name', 'slug' => 'slug', 'metadata' => 'metadata'], 'name', [
            'slug_taken' => __('console.that_url_handle_is_already_used_by_another_organization_f2791721'),
        ]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.organization_updated_86eb8ecc'));
    }

    public function suspend(): RedirectResponse
    {
        $organization = $this->organizationInUrl();

        $this->assertEnvironmentAdmin();

        $result = $this->act(SuspendOrganization::class, ['id' => $organization]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.organization_suspended_b3cca290'));
    }

    public function reactivate(): RedirectResponse
    {
        $organization = $this->organizationInUrl();

        $this->assertEnvironmentAdmin();

        $result = $this->act(ReactivateOrganization::class, ['id' => $organization]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.organization_reactivated_cdae875e'));
    }

    /**
     * Soft-delete the tenant: status → Deleted, which takes it out of every list AND refuses
     * its members at the request pipeline, the device flow and the consent screen, exactly as
     * a suspension does — {@see Organizations::archive()}, through the action.
     */
    public function destroy(): RedirectResponse
    {
        $organization = $this->organizationInUrl();

        $this->assertEnvironmentAdmin();

        $result = $this->act(DeleteOrganization::class, ['id' => $organization]);

        return $result instanceof RedirectResponse
            ? $result
            : to_route('environment.organizations')->with('status', __('console.organization_deleted_460d291d'));
    }

    /**
     * Add an existing user by address, with the access roles ticked — one action, so a
     * segregation-of-duties conflict refuses the lot rather than leaving half of it behind.
     */
    public function addMember(AddOrganizationMemberRequest $request): RedirectResponse
    {
        $organization = $this->organizationInUrl();

        $this->assertEnvironmentAdmin();

        $result = $this->act(AddMember::class, [
            'organization_id' => $organization,
            'email' => $request->email(),
            'role' => $request->role()->value,
            'roles' => $request->accessRoleIds(),
        ], ['email' => 'email', 'user_id' => 'email', 'role' => 'role', 'roles' => 'accessRoles', 'organization_id' => 'email'], 'email', [
            'already_member' => __('console.that_user_is_already_a_member_51a8e24c'),
        ]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        if ($result->status === 200) {
            return back()->withInput()->withErrors(['email' => __('console.that_user_is_already_a_member_51a8e24c')]);
        }

        return back()->with('status', __('console.member_added_ccf89720'));
    }

    public function changeMemberRole(Request $request, string $member): RedirectResponse
    {
        $organization = $this->organizationInUrl();

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
        ], ['role' => 'role'], 'role', ['last_owner' => __('console.an_organization_must_keep_at_least_one_owner_ae832206')]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.built_in_role_updated_a1cff526'));
    }

    /**
     * Grant or revoke one RBAC access-role for a member.
     *
     * AN EXPLICIT SET rather than a toggle: the row's checkbox and a retried request must
     * not disagree about which state was asked for. One action per state; segregation of
     * duties refuses a toxic pair and the refusal names both roles.
     */
    public function setAccessRole(Request $request, string $member): RedirectResponse
    {
        $organization = $this->organizationInUrl();

        $this->assertEnvironmentAdmin();

        $request->validate([
            'role' => ['required', 'string'],
            'granted' => ['required', 'boolean'],
        ]);

        $input = ['organization_id' => $organization, 'user_id' => $member, 'role_id' => $request->string('role')->toString()];

        if (! $request->boolean('granted')) {
            $result = $this->act(RevokeMemberRole::class, $input, ['role_id' => 'accessRole'], 'accessRole');

            return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.access_revoked_b474f694'));
        }

        $result = $this->act(GrantMemberRole::class, $input, ['role_id' => 'accessRole'], 'accessRole');

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.access_granted_3c3c8f0f'));
    }

    public function removeMember(string $member): RedirectResponse
    {
        $organization = $this->organizationInUrl();

        $this->assertEnvironmentAdmin();

        $result = $this->act(RemoveMember::class, [
            'organization_id' => $organization,
            'user_id' => $member,
        ], ['user_id' => 'member'], 'member', ['last_owner' => __('console.an_organization_must_keep_at_least_one_owner_ae832206')]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.member_removed_d6fe67b5'));
    }

    /**
     * The invitee accepts via the emailed token — nobody is added without consent. The same
     * action an app's backend sends one with, signed with this administrator's name.
     */
    public function invite(InviteOrganizationMemberRequest $request): RedirectResponse
    {
        $organization = $this->organizationInUrl();

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

        return back()->with('status', __('console.invitation_sent_to_part1_5b76ee7f', ['part1' => $request->email()]));
    }

    public function resendInvitation(string $invitation): RedirectResponse
    {
        $organization = $this->organizationInUrl();

        $this->assertEnvironmentAdmin();

        $result = $this->attempt(ResendInvitation::class, ['organization_id' => $organization, 'invitation_id' => $invitation]);

        if ($result instanceof ActionRefused) {
            return back()->with('error', $result->getMessage());
        }

        $sent = $result->value;

        return back()->with('status', $sent instanceof SentInvitation
            ? __('console.invitation_sent_again_to_part1_eefdfbca', ['part1' => $sent->invitation->email])
            : __('console.invitation_sent_again_31ffca77'));
    }

    public function revokeInvitation(string $invitation): RedirectResponse
    {
        $organization = $this->organizationInUrl();

        $this->assertEnvironmentAdmin();

        $result = $this->attempt(RevokeInvitation::class, ['organization_id' => $organization, 'invitation_id' => $invitation]);

        if ($result instanceof ActionRefused) {
            return back()->with('error', $result->getMessage());
        }

        return back()->with('status', __('console.invitation_revoked_that_link_no_longer_works_c1b9fc24'));
    }

    /**
     * Make a member the organization's owner.
     *
     * From OUTSIDE the organization, so there is no outgoing owner to name: every current
     * owner steps down to admin. That is also how an organization this console created —
     * which starts with no owner at all — gets its first one.
     */
    public function transferOwnership(string $member): RedirectResponse
    {
        $organization = $this->organizationInUrl();

        $this->assertEnvironmentAdmin();

        $result = $this->act(TransferOwnership::class, ['id' => $organization, 'user_id' => $member], ['user_id' => 'member'], 'member');

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.ownership_transferred_ca25b345'));
    }

    public function addDomain(AddOrganizationDomainRequest $request): RedirectResponse
    {
        $organization = $this->organizationInUrl();

        $this->assertEnvironmentAdmin();

        $result = $this->act(AddOrganizationDomain::class, [
            'organization_id' => $organization,
            'domain' => $request->domain(),
        ], ['domain' => 'domain'], 'domain');

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', __('console.domain_added_add_the_dns_txt_record_shown_below_then_ve_6b181a34'));
    }

    public function verifyDomain(string $domain): RedirectResponse
    {
        $organization = $this->organizationInUrl();

        $this->assertEnvironmentAdmin();

        $result = $this->act(VerifyOrganizationDomain::class, ['organization_id' => $organization, 'domain_id' => $domain], [], 'domain');

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.domain_verified_11272c96'));
    }

    /**
     * Capture routes everyone on this email domain to the organization's SSO connection, so
     * turning it on is refused for a domain nobody proved they own. The page's button flips
     * it; the action is told which state it means.
     */
    public function toggleCapture(string $domain): RedirectResponse
    {
        $organization = $this->organizationInUrl();

        $this->assertEnvironmentAdmin();

        $model = $this->ownedDomain($organization, $domain);

        $result = $this->act(SetDomainCapture::class, [
            'organization_id' => $model->organization_id,
            'domain_id' => $model->id,
            'enabled' => ! $model->capture,
        ], [], 'domain');

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.domain_capture_updated_eb431b8f'));
    }

    public function removeDomain(string $domain): RedirectResponse
    {
        $organization = $this->organizationInUrl();

        $this->assertEnvironmentAdmin();

        $result = $this->act(RemoveOrganizationDomain::class, ['organization_id' => $organization, 'domain_id' => $domain], [], 'domain');

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.domain_removed_2ccaa6d3'));
    }

    /** The environment console's own gate: a membership administering THIS environment. */
    private function assertEnvironmentAdmin(): void
    {
        abort_unless(app(EnvironmentAdminAuth::class)->check(), 403);
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
     * The organization the URL names, checked against this environment and bound before
     * this controller ran (`console.org`, {@see BindConsoleOrganization})
     * — so another environment's id, or a made-up one, is a 404 before any action sees it.
     */
    private function organizationInUrl(): string
    {
        return (string) $this->routeOrganizationId();
    }
}
