<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Members\AddMember;
use App\Actions\Members\ChangeMemberRole;
use App\Actions\Members\GrantMemberRole;
use App\Actions\Members\RemoveMember;
use App\Actions\Members\RevokeMemberRole;
use App\Actions\Users\CreateUser;
use App\Actions\Users\DeactivateUser;
use App\Actions\Users\EraseUser;
use App\Actions\Users\GrantStaffRole;
use App\Actions\Users\MarkEmailVerified;
use App\Actions\Users\ReactivateUser;
use App\Actions\Users\ResetMfa;
use App\Actions\Users\RevokeAllUserSessions;
use App\Actions\Users\RevokeStaffRole;
use App\Actions\Users\RevokeUserSession;
use App\Actions\Users\SendPasswordReset;
use App\Actions\Users\SendVerification;
use App\Actions\Users\SetUserPassword;
use App\Actions\Users\UpdateUser;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\RoleOptionProps;
use App\Http\Props\Shared\SimplePaginationProps;
use App\Http\Props\Shared\StaffRoleProps;
use App\Http\Props\Shared\SupportSessionProps;
use App\Http\Requests\Console\AssignUserOrganizationRequest;
use App\Http\Requests\Console\CreateEnvironmentUserRequest;
use App\Http\Requests\Console\SaveEnvironmentUserRequest;
use App\Http\Requests\Console\SetUserPasswordRequest;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\Console\LikeTerm;
use App\Platform\Console\Vocabulary;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Help\HelpTopic;
use App\Platform\OrgAccessRoles;
use App\Platform\OrgRoles;
use App\Platform\Staff\Contracts\StaffRoles;
use App\Platform\SupportAccess\Contracts\SupportAccess;
use App\Platform\SupportAccess\ValueObjects\SupportApp;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Inertia\Response;

/**
 * ENVIRONMENT PLANE › USERS — every end-user identity in this environment, and the whole
 * lifecycle of one of them.
 *
 * THERE IS DELIBERATELY NO DELETE HERE — THERE IS AN ERASURE. This page once carried a
 * delete: it stripped the memberships, called `$user->delete()`, and reported "User
 * deleted." The schema carries no foreign key on `user_id` anywhere, so nothing ever
 * refused the delete and the "they still have linked records" guard it was wrapped in could
 * not fire. What actually survived the row: sessions, passkeys, MFA factors and TOTP seeds,
 * password history, `identities.raw` (the person's whole IdP profile), magic links,
 * email-verification tokens, OAuth access/refresh tokens, `directory_users.resource` (the
 * whole SCIM payload) and role assignments. No domain event fired, so nothing downstream
 * was deprovisioned, and no audit entry recorded the act.
 *
 * An administrator being told an erasure happened when it did not is worse than having no
 * button at all — it retires the request. So the button came back only once there was an
 * erasure behind it: {@see self::erase()} runs `users.erase` ({@see EraseUser}), the
 * framework's one-transaction GDPR Art. 17 pipeline plus this app's own stores, behind a
 * fresh credential and the person's address typed out. Deactivation stays the reversible
 * off-switch beside it.
 *
 * EVERY MUTATION RE-RESOLVES THE USER from the URL through the environment-scoped model,
 * so an id from another environment 404s rather than being acted on. Under Livewire the
 * target was a component property and had to be `#[Locked]` to stop the browser retargeting
 * the page at somebody else after mount; a route parameter cannot be retargeted at all.
 *
 * EVERY WRITE IS AN ACTION (app/Actions/Users, app/Actions/Members): the same change, rules
 * and audit entry the management API and MCP make, run as the person signed in. What stays
 * here is the console's own — the fresh-password step-up before a takeover-class change, the
 * unverified-address hold on the person creating somebody, and the page's own wording.
 */
final readonly class EnvironmentUserController extends ConsoleController
{
    private const PER_PAGE = 25;

    /** The most recent sessions shown; enough to recognise a device, not a log. */
    private const SESSION_LIMIT = 50;

    public function index(Request $request): Response
    {
        $this->assertEnvironmentAdmin();

        $query = User::query()->orderBy('email');

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            // Through LikeTerm: an email address is the one column almost guaranteed to
            // carry a literal underscore, and read as a wildcard it matched users the
            // administrator was not searching for.
            $like = LikeTerm::containing($term);

            $query->where(fn (Builder $q): Builder => $q
                ->whereRaw($like->sqlFor('email'), [$like->pattern])
                ->orWhereRaw($like->sqlFor('name'), [$like->pattern]));
        }

        /*
         * simplePaginate, not paginate: `paginate()` adds a COUNT(*) over the filtered set
         * on every search, and the search is a leading wildcard that no B-tree index can
         * serve — so the count is a full scan of the environment's users, twice over, to
         * render page numbers.
         */
        $page = $query->simplePaginate(self::PER_PAGE)->withQueryString();

        return $this->page('environment/users/index', Vocabulary::USERS, [
            'help' => HelpProps::for(HelpTopic::Users),
            'users' => array_map(static fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status->value,
                'verified' => $user->email_verified_at !== null,
                'href' => route('environment.users.show', $user->id),
            ], $page->getCollection()->all()),
            'pagination' => SimplePaginationProps::from($page),
            'search' => $term,
            'createHref' => route('environment.users.create'),
        ]);
    }

    public function create(): Response
    {
        $this->assertEnvironmentAdmin();

        return $this->page('environment/users/create', 'New user', [
            'indexHref' => route('environment.users'),
            'storeHref' => route('environment.users.store'),
        ]);
    }

    public function store(CreateEnvironmentUserRequest $request): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        /*
         * BECAUSE THIS SENDS MAIL TO AN ADDRESS THE CREATOR CHOSE.
         *
         * Every other gated write on this console is gated for reaching outside the tenant
         * — a webhook, a directory, a hook, an OAuth client. This one reaches further than
         * any of them: it puts a live, one-click sign-in link into an arbitrary inbox, over
         * the platform's own domain and signature, at the request of somebody whose own
         * address nobody has confirmed. An unverified account is one somebody else may
         * actually own, which is exactly the account not to hand a mailer to. The hold is
         * on the PERSON, so it is asked here and not in the action a key runs too.
         */
        app(VerifiedEmailGate::class)->require('create a user');

        // AND ACTUALLY SEND SOMETHING when asked: a magic link rather than an invitation,
        // because an invitation exists to make a membership, and an environment where
        // organizations are not used has none to join.
        $result = $this->act(CreateUser::class, [
            'email' => $request->email(),
            'name' => $request->name(),
            'send_sign_in_link' => $request->sendLink(),
        ], ['email' => 'email', 'name' => 'name', 'send_sign_in_link' => 'sendLink'], 'email');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        $created = $result->value;
        $id = is_object($created) && isset($created->id) && is_string($created->id) ? $created->id : '';

        return to_route('environment.users.show', $id)
            ->with('status', $request->sendLink()
                ? 'User created — a sign-in link is on its way to '.$request->email().'.'
                : 'User created. They have no way to sign in until you send them a link.');
    }

    public function show(
        Request $request,
        string $user,
        Memberships $memberships,
        OrgAccessRoles $catalog,
        Mfa $mfa,
        StaffRoles $staff,
        SupportAccess $support,
    ): Response {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($user);

        /*
         * Names for the memberships this user already holds — every organization, whatever
         * its status, because a membership of a suspended one still has to be legible here
         * rather than showing a bare id.
         */
        $names = $this->organizationNames();

        $rows = [];

        foreach ($memberships->forUser($model->id) as $membership) {
            $roles = $catalog->assignable($membership->organization_id);

            $rows[] = [
                'organizationId' => $membership->organization_id,
                'organizationName' => $names[$membership->organization_id] ?? $membership->organization_id,
                'role' => $membership->role->value,
                // Invited and suspended members cannot be acted as: a support session
                // would put them into an organization its administrators have not.
                'active' => $membership->status === MembershipStatus::Active,
                'managesOrganization' => $membership->role->canManageOrganization(),
                // Per-org RBAC catalogue + what this user holds there. Roles are largely
                // environment-wide, but app-declared roles are scoped per organization.
                'accessRoles' => $this->accessRoleProps($roles, $catalog->appNames($roles)),
                'accessRoleIds' => array_values(array_filter(
                    $catalog->assignedTo($membership->organization_id, $model->id),
                    'is_string',
                )),
                'href' => route('environment.organizations.show', $membership->organization_id),
                'urls' => [
                    'role' => route('environment.users.organizations.role', [$model->id, $membership->organization_id]),
                    'accessRole' => route('environment.users.organizations.access', [$model->id, $membership->organization_id]),
                    'remove' => route('environment.users.organizations.remove', [$model->id, $membership->organization_id]),
                ],
            ];
        }

        /*
         * The roles offered as this user is ADDED to an organization, for whichever one the
         * picker currently names. Read from the query string and re-fetched by a partial
         * reload, so the list is one org's rather than every org's — a page that shipped
         * the whole catalogue would grow with the environment and be wrong for all but one
         * of them anyway.
         */
        $joining = trim($request->string('org')->toString());
        $joinable = $this->joinableOrganizations();
        $joiningRoles = $joining !== '' && array_key_exists($joining, $joinable)
            ? $catalog->assignable($joining)
            : collect();

        return $this->page('environment/users/show', $model->name ?? $model->email, [
            'user' => [
                'id' => $model->id,
                'name' => $model->name,
                'email' => $model->email,
                'status' => $model->status->value,
                'verified' => $model->email_verified_at !== null,
                'hasMfa' => $mfa->hasConfirmedTotp($model->id),
                'requiresPasswordChange' => app(AdminPasswords::class)->requiresChange($model->id),
            ],
            'memberships' => $rows,
            'joinableOrganizations' => array_map(
                static fn (string $id): array => ['value' => $id, 'label' => $joinable[$id]],
                array_keys($joinable),
            ),
            'joiningOrganization' => $joining,
            'joiningAccessRoles' => $this->accessRoleProps($joiningRoles, $catalog->appNames($joiningRoles)),
            /*
             * STAFF ROLES — grants that name no organization: a support agent acting across
             * every organization, somebody who has joined none, or an app with no tenancy of
             * its own to hang a grant on. Any role no organization owns, an app's own
             * included (that one reaches only that app's tokens); the Staff page lists the
             * same grants for everybody at once.
             */
            'staffRoles' => StaffRoleProps::list($staff->grantable()),
            'heldStaffRoles' => $staff->heldBy($model->id),
            'staffHref' => route('environment.staff'),
            /*
             * SUPPORT ACCESS — sign in to one of the environment's own apps as this person.
             * Only the apps a support session can reach are offered, and only the
             * organizations they are an active member of; both are asked again on the way
             * in, because a posted id is anything a client chooses to send.
             */
            'support' => [
                'apps' => array_map(static fn (SupportApp $app): array => [
                    'value' => $app->clientId,
                    'label' => $app->name,
                ], $support->eligibleApps()),
                'organizations' => array_values(array_map(
                    static fn (array $row): array => ['value' => $row['organizationId'], 'label' => $row['organizationName']],
                    array_filter($rows, static fn (array $row): bool => $row['active']),
                )),
                'maxMinutes' => $support->maxMinutes(),
                'sessions' => SupportSessionProps::list($support->activeForUser($model->id)),
                'help' => HelpProps::for(HelpTopic::SupportAccess),
                'startHref' => route('environment.users.support-sessions.store', $model->id),
            ],
            'sessions' => $this->sessionProps($model->id),
            // The same lists every other roster in the product offers. The membership rows
            // name Owner so an owner's row says what it holds; nothing offers it.
            'assignableRoles' => RoleOptionProps::organization(),
            'membershipRoles' => RoleOptionProps::organization(withOwner: true),
            'indexHref' => route('environment.users'),
            'urls' => [
                'update' => route('environment.users.update', $model->id),
                'password' => route('environment.users.password', $model->id),
                'passwordReset' => route('environment.users.password-reset', $model->id),
                'resendVerification' => route('environment.users.verification', $model->id),
                'markVerified' => route('environment.users.verify', $model->id),
                'resetMfa' => route('environment.users.mfa', $model->id),
                'deactivate' => route('environment.users.deactivate', $model->id),
                'reactivate' => route('environment.users.reactivate', $model->id),
                'erase' => route('environment.users.erase', $model->id),
                'revokeAllSessions' => route('environment.users.sessions.revoke-all', $model->id),
                'assignOrganization' => route('environment.users.organizations.store', $model->id),
                'environmentRole' => route('environment.users.roles', $model->id),
                'impersonate' => route('environment.impersonate', $model->id),
            ],
        ]);
    }

    /**
     * Through the action, so the change is audited as `user.updated` and emitted — which is
     * what makes it reach a webhook subscriber and the outbound SCIM push. The contract
     * clears the verification on a changed address.
     */
    public function update(SaveEnvironmentUserRequest $request, string $user): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(UpdateUser::class, [
            'id' => $user,
            'name' => $request->name(),
            'email' => $request->email(),
        ], ['name' => 'name', 'email' => 'email'], 'email');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Profile updated.');
    }

    public function setPassword(SetUserPasswordRequest $request, string $user): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($user);

        /*
         * A FRESH PASSWORD BEFORE YOU CHOOSE SOMEBODY ELSE'S. This replaces any user in the
         * environment's credential and, with "reveal", hands the plaintext straight back.
         * A browser left open on a desk was the attack.
         *
         * AFTER validation, deliberately: a step-up in front of a shape check answers
         * garbage input with a password prompt instead of an error message, which trains
         * people to type their password at a screen they have not read.
         */
        $challenge = $this->stepUp(
            $model->id,
            'Setting a password replaces this person’s credential; with “reveal” you are shown it.',
        );

        if ($challenge !== null) {
            return $challenge;
        }

        $result = $this->act(SetUserPassword::class, [
            'id' => $model->id,
            'password' => $request->password(),
            'reason' => $request->reason(),
            'temporary' => $request->temporary(),
            'expires_in_hours' => $request->integer('expiryHours'),
            'revoke' => $request->revoke()->value,
            // "Reveal" hands it over on this screen instead of by mail.
            'send_email' => ! $request->reveal(),
        ], ['password' => 'password', 'reason' => 'reason', 'revoke' => 'revoke', 'expires_in_hours' => 'expiryHours'], 'password');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        if (! $request->reveal()) {
            return back()->with('status', 'Password set and emailed to '.$model->email.'.');
        }

        /*
         * ON THE FLASH CHANNEL, which is never written into the history entry — so the
         * credential is not sitting in a back-button page restore, and a reload of this
         * screen does not show it a second time.
         */
        $this->inertia->flash('issuedPassword', $request->password());

        return back()->with('status', 'Password set. Copy it now — it is shown once.');
    }

    public function sendPasswordReset(string $user): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($user);

        $result = $this->act(SendPasswordReset::class, ['id' => $model->id]);

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', 'Password reset email sent to '.$model->email.'.');
    }

    public function resendVerification(string $user): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($user);

        if ($model->email_verified_at !== null) {
            return back();
        }

        $result = $this->act(SendVerification::class, ['id' => $model->id]);

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', 'Verification email sent to '.$model->email.'.');
    }

    public function markVerified(string $user): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($user);

        // Marking an address verified is what lets it be used to recover the account, so it
        // is a takeover with one more step rather than a lesser action.
        $challenge = $this->stepUp(
            $model->id,
            'Marking this address verified lets it be used to recover this user’s sign-in.',
        );

        if ($challenge !== null) {
            return $challenge;
        }

        $result = $this->act(MarkEmailVerified::class, ['id' => $model->id]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Email marked as verified.');
    }

    public function resetMfa(string $user): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($user);

        // Taking away someone's second factor leaves their password the only thing between
        // an attacker and the account — the step that makes the two above worth doing.
        $challenge = $this->stepUp(
            $model->id,
            'Resetting two-factor leaves this user protected by their password alone.',
        );

        if ($challenge !== null) {
            return $challenge;
        }

        // Audited as `user.mfa_disabled` with the administrator as the actor: an access
        // review must be able to tell somebody turning off their own factor from this.
        $result = $this->act(ResetMfa::class, ['id' => $model->id]);

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', 'Two-factor authentication reset — the user must re-enroll.');
    }

    public function deactivate(string $user): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(DeactivateUser::class, ['id' => $user]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'User deactivated — they can no longer sign in.');
    }

    /**
     * ERASE THIS PERSON — GDPR Art. 17 — through the same action the management API and MCP
     * run, so the rule (an organization's only owner is refused), the receipt and the
     * `user.erased` tombstone are the action's.
     *
     * TWO GATES, because nothing undoes it. A fresh credential, like every takeover-shaped
     * action on this page — an administrator's browser left open on a desk must not be
     * enough. And the person's address typed out, checked HERE rather than only in the
     * dialog: the dialog is the page being careful, and a crafted POST never opens it.
     *
     * After it, the row is still there — pseudonymised, disabled, id kept — so the list is
     * where to land: this page would now describe somebody called `erased-…`.
     */
    public function erase(Request $request, string $user): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($user);

        $challenge = $this->stepUp(
            $model->id,
            'Erasing a person deletes their credentials, memberships and personal data for good. Nothing brings it back.',
        );

        if ($challenge !== null) {
            return $challenge;
        }

        if (! hash_equals($model->email, trim($request->string('confirmation')->toString()))) {
            return back()->withErrors(['erase' => 'Type this user’s email address exactly to erase them.']);
        }

        $result = $this->act(EraseUser::class, ['id' => $model->id], fallback: 'erase');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return to_route('environment.users')->with(
            'status',
            'User erased. Their account is pseudonymised and the erasure is recorded as user.erased in the audit log.',
        );
    }

    public function reactivate(string $user): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(ReactivateUser::class, ['id' => $user]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'User reactivated.');
    }

    public function revokeSession(string $user, string $session): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        // Only a session belonging to THIS env-scoped user resolves (deny-by-default).
        $result = $this->act(RevokeUserSession::class, ['id' => $user, 'session_id' => $session]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Session revoked.');
    }

    /**
     * Sign this person out everywhere — the sessions AND the grants: a refresh token an app
     * held went on minting access tokens after the logout otherwise.
     */
    public function revokeAllSessions(string $user): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(RevokeAllUserSessions::class, ['id' => $user]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'All sessions revoked.');
    }

    /**
     * Add this person to an organization, with the access roles ticked — one action, so a
     * segregation-of-duties conflict refuses the lot rather than leaving half of it behind.
     *
     * A suspended or archived organization takes no new members: the picker does not offer
     * one, and the action refuses a posted id that names one anyway.
     */
    public function assignOrganization(AssignUserOrganizationRequest $request, string $user): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(AddMember::class, [
            'organization_id' => $request->organizationId(),
            'user_id' => $user,
            'role' => $request->role()->value,
            'roles' => $request->accessRoleIds(),
        ], ['organization_id' => 'organization', 'user_id' => 'organization', 'role' => 'role', 'roles' => 'organization'], 'organization', [
            'already_member' => 'The user is already a member of that organization.',
        ]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        // The same tier again is not an error to a machine (it is idempotent); to a person
        // who meant to add somebody, "already a member" is the answer.
        if ($result->status === 200) {
            return back()->withErrors(['organization' => 'The user is already a member of that organization.']);
        }

        return back()->with('status', 'User added to the organization.');
    }

    public function changeMembershipRole(Request $request, string $user, string $organization): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        // Untrusted: an unassignable or unknown role is refused outright rather than
        // coerced to a default, and the refusal names the choices.
        $next = OrgRoles::parse($request->string('role')->toString());

        if ($next === null) {
            return back()->withErrors(['role' => OrgRoles::message()]);
        }

        $result = $this->act(ChangeMemberRole::class, [
            'organization_id' => $organization,
            'user_id' => $user,
            'role' => $next->value,
        ], ['role' => 'role'], 'role', ['last_owner' => 'An organization must keep at least one owner.']);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Built-in role updated.');
    }

    /**
     * Grant or revoke one RBAC access-role for this user in one organization.
     *
     * AN EXPLICIT SET rather than a toggle: a retried request and the checkbox must not
     * disagree about which state was asked for. One action per state.
     */
    public function setAccessRole(Request $request, string $user, string $organization): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $input = ['organization_id' => $organization, 'user_id' => $user, 'role_id' => $request->string('role')->toString()];

        if (! $request->boolean('granted')) {
            $result = $this->act(RevokeMemberRole::class, $input, ['role_id' => 'role'], 'role');

            return $result instanceof RedirectResponse ? $result : back()->with('status', 'Role revoked.');
        }

        $result = $this->act(GrantMemberRole::class, $input, ['role_id' => 'role'], 'role');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Role granted.');
    }

    /**
     * Grant or take back a STAFF role — a role held everywhere in this environment.
     *
     * The same action the Staff page and the management API run, so segregation of duties
     * is asked one way: in every organization the person belongs to, and against the staff
     * roles they already hold — and a refusal names where the conflicting half sits.
     */
    public function setEnvironmentRole(Request $request, string $user, StaffRoles $staff): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $roleId = $request->string('role')->toString();

        // Only a role no organization owns. The action refuses anything else too; asking
        // here first means a posted id that matches nothing is refused by name.
        if (! $staff->isGrantable($roleId)) {
            return back()->withErrors(['staffRole' => 'That role cannot be granted across the environment.']);
        }

        $input = ['id' => $user, 'role_id' => $roleId];

        if (! $request->boolean('granted')) {
            $result = $this->act(RevokeStaffRole::class, $input, ['role_id' => 'staffRole'], 'staffRole');

            return $result instanceof RedirectResponse ? $result : back()->with('status', 'Admin & support role taken back.');
        }

        $result = $this->act(GrantStaffRole::class, $input, ['role_id' => 'staffRole'], 'staffRole');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Admin & support role granted.');
    }

    public function removeMembership(string $user, string $organization): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(RemoveMember::class, [
            'organization_id' => $organization,
            'user_id' => $user,
        ], ['user_id' => 'organization'], 'organization', ['last_owner' => 'An organization must keep at least one owner.']);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Removed from the organization.');
    }

    private function assertEnvironmentAdmin(): void
    {
        abort_if(app(EnvironmentAdminAuth::class)->membership() === null, 403);
    }

    /**
     * The user this page is about, resolved through the environment-scoped model — an id
     * from another environment never matches.
     */
    private function resolve(string $user): User
    {
        $model = User::query()->whereKey($user)->first();

        abort_if($model === null, 404);

        return $model;
    }

    /**
     * Demand a fresh credential before a takeover action, and say why on the screen.
     *
     * Returns the redirect the caller must return, or null when the window is already open.
     * The reason is per-action rather than one generic sentence, because "this is a
     * protected action" is what teaches people to type a password without reading the page.
     */
    private function stepUp(string $userId, string $reason): ?RedirectResponse
    {
        $route = app(ConsoleStepUp::class)->challenge(
            'environment.users.show',
            'environment.users.show',
            ['user' => $userId],
            $reason,
        );

        return $route === null ? null : to_route($route);
    }

    /**
     * Names for every organization in this environment, whatever its status.
     *
     * @return array<string, string>
     */
    private function organizationNames(): array
    {
        $names = [];

        foreach (Organization::query()->orderBy('name')->get(['id', 'name']) as $organization) {
            $names[(string) $organization->id] = (string) $organization->name;
        }

        return $names;
    }

    /**
     * What may be JOINED — a narrower question than what exists. Offering a deleted or
     * suspended organization in the picker invites exactly the thing the guard in
     * {@see self::assignOrganization()} refuses.
     *
     * @return array<string, string>
     */
    private function joinableOrganizations(): array
    {
        $names = [];

        $rows = Organization::query()
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get(['id', 'name']);

        foreach ($rows as $organization) {
            $names[(string) $organization->id] = (string) $organization->name;
        }

        return $names;
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

    /**
     * @return list<array<string, mixed>>
     */
    private function sessionProps(string $userId): array
    {
        $sessions = Session::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('last_active_at')
            ->limit(self::SESSION_LIMIT)
            ->get();

        $rows = [];

        foreach ($sessions as $session) {
            $rows[] = [
                'id' => $session->id,
                'device' => $session->user_agent,
                'ip' => $session->ip,
                'lastActive' => $session->last_active_at?->diffForHumans(),
                // Worth calling out: a session somebody else opened as this person.
                'impersonation' => in_array('impersonation', $session->amr, true),
                'revokeHref' => route('environment.users.sessions.revoke', [$userId, $session->id]),
            ];
        }

        return $rows;
    }
}
