<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\SimplePaginationProps;
use App\Http\Requests\Console\AssignUserOrganizationRequest;
use App\Http\Requests\Console\CreateEnvironmentUserRequest;
use App\Http\Requests\Console\SaveEnvironmentUserRequest;
use App\Http\Requests\Console\SetUserPasswordRequest;
use App\Mail\AdminAssignedPasswordMail;
use App\Mail\EmailVerificationMail;
use App\Mail\MagicLinkMail;
use App\Mail\PasswordResetMail;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\Console\LikeTerm;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\GrantAccessRole;
use App\Platform\MailLinks;
use App\Platform\OrgAccessRoles;
use App\Platform\OrganizationAccess;
use App\Platform\OrgRoles;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\EmailVerification;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Identity\ValueObjects\AdminPasswordAssignment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Exceptions\LastOwner;
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
 * THERE IS DELIBERATELY NO DELETE HERE. This page once carried one: it stripped the
 * memberships, called `$user->delete()`, and reported "User deleted." The schema carries
 * no foreign key on `user_id` anywhere, so nothing ever refused the delete and the "they
 * still have linked records" guard it was wrapped in could not fire. What actually
 * survived the row: sessions, passkeys, MFA factors and TOTP seeds, password history,
 * `identities.raw` (the person's whole IdP profile), magic links, email-verification
 * tokens, OAuth access/refresh tokens, `directory_users.resource` (the whole SCIM payload)
 * and role assignments. No domain event fired, so nothing downstream was deprovisioned,
 * and no audit entry recorded the act.
 *
 * An administrator being told an erasure happened when it did not is worse than having no
 * button at all — it retires the request. Deactivation is what this console can honestly
 * do, so deactivation is what it offers.
 *
 * EVERY MUTATION RE-RESOLVES THE USER from the URL through the environment-scoped model,
 * so an id from another environment 404s rather than being acted on. Under Livewire the
 * target was a component property and had to be `#[Locked]` to stop the browser retargeting
 * the page at somebody else after mount; a route parameter cannot be retargeted at all.
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

        return $this->page('environment/users/index', 'Users', [
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

    public function store(CreateEnvironmentUserRequest $request, Subjects $subjects): RedirectResponse
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
         * actually own, which is exactly the account not to hand a mailer to.
         */
        app(VerifiedEmailGate::class)->require('create a user');

        if ($subjects->findByEmail($request->email()) !== null) {
            return back()
                ->withInput()
                ->withErrors(['email' => 'A user with that email already exists in this environment.']);
        }

        $subject = $subjects->create($request->email(), $request->name());

        /*
         * AND ACTUALLY SEND SOMETHING. The page promised "they complete sign-in via a
         * link" and nothing was sent at all: a row appeared here and the person heard
         * nothing, so every environment onboarding needed an email the administrator wrote
         * by hand.
         *
         * A magic link rather than an organization invitation, because an invitation is
         * "join this organization" — it exists to create a membership — and an environment
         * where organizations are not used has none to join.
         */
        if ($request->sendLink()) {
            // In THIS environment's context, not the platform root: the subject was just
            // created here, and a link minted in the root would redeem against a user pool
            // that does not contain them.
            $token = app(MagicLink::class)->request($request->email());

            Mail::to($request->email())->send(new MagicLinkMail(
                app(MailLinks::class)->route('magic.redeem', $token),
            ));
        }

        $user = User::query()->where('email', $request->email())->first();

        return to_route('environment.users.show', $user->id ?? $subject->id)
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
             * GRANTS THAT NAME NO ORGANIZATION — the case an org-scoped grant cannot
             * describe: a support agent acting across every customer, somebody who has
             * joined none, or an app with no tenancy of its own to hang a grant on. Before
             * this the only way to give such a person anything was to invent a membership.
             */
            'everywhereRoles' => $this->accessRoleProps(
                $catalog->grantableEverywhere(),
                $catalog->appNames($catalog->grantableEverywhere()),
            ),
            'heldEverywhere' => array_values(array_filter(
                app(Roles::class)->everywhereFor($model->id),
                'is_string',
            )),
            'sessions' => $this->sessionProps($model->id),
            'assignableRoles' => array_map(static fn ($role): array => [
                'value' => $role->value,
                'label' => $role->label(),
            ], OrgRoles::assignable()),
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
                'revokeAllSessions' => route('environment.users.sessions.revoke-all', $model->id),
                'assignOrganization' => route('environment.users.organizations.store', $model->id),
                'environmentRole' => route('environment.users.roles', $model->id),
                'impersonate' => route('environment.impersonate', $model->id),
            ],
        ]);
    }

    public function update(SaveEnvironmentUserRequest $request, string $user, Subjects $subjects): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($user);

        $changed = mb_strtolower($request->email()) !== mb_strtolower($model->email);

        if ($changed && User::query()->where('email', $request->email())->whereKeyNot($model->id)->exists()) {
            return back()->withErrors(['email' => 'Another user already uses that email in this environment.']);
        }

        /*
         * Through the contract, so the change is audited as `user.updated` and emitted —
         * which is what makes it reach a webhook subscriber and the outbound SCIM push.
         * This was the last direct model write on this page: it left no record of who
         * changed the account's primary identifier, which is also its recovery channel.
         * The contract clears the verification on a changed email for us.
         */
        $subjects->update($model->id, $request->name(), $changed ? $request->email() : null);

        return back()->with('status', 'Profile updated.');
    }

    public function setPassword(SetUserPasswordRequest $request, string $user, AdminPasswords $passwords): RedirectResponse
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

        $passwords->assign(new AdminPasswordAssignment(
            userId: $model->id,
            password: $request->password(),
            temporary: $request->temporary(),
            expiresAt: $request->expiresAt(),
            revoke: $request->revoke(),
            actorType: 'account_member',
            actorId: app(EnvironmentAdminAuth::class)->membership()?->id,
            reason: $request->reason(),
        ));

        if (! $request->reveal()) {
            Mail::to($model->email)->send(new AdminAssignedPasswordMail(
                password: $request->password(),
                temporary: $request->temporary(),
                expiresAt: $request->expiresAt()?->toDayDateTimeString(),
            ));

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

    public function sendPasswordReset(string $user, PasswordReset $resets, MailLinks $links): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($user);

        $token = $resets->request($model->email);

        if (is_string($token)) {
            Mail::to($model->email)->send(new PasswordResetMail($links->route('password.reset', $token)));
        }

        return back()->with('status', 'Password reset email sent to '.$model->email.'.');
    }

    public function resendVerification(string $user, EmailVerification $verification, MailLinks $links): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($user);

        if ($model->email_verified_at !== null) {
            return back();
        }

        $token = $verification->issue($model->id, $model->email);

        Mail::to($model->email)->send(new EmailVerificationMail($links->route('verification.verify', $token)));

        return back()->with('status', 'Verification email sent to '.$model->email.'.');
    }

    public function markVerified(string $user, Subjects $subjects): RedirectResponse
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

        $subjects->markEmailVerified($model->id, $model->email);

        return back()->with('status', 'Email marked as verified.');
    }

    public function resetMfa(string $user, Mfa $mfa): RedirectResponse
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

        // Through the contract, so the reset is audited as `user.mfa_disabled`. This used
        // to delete the rows directly — an admin taking away someone's second factor was
        // the one MFA action in the console that left no trace.
        $mfa->disable($model->id);

        return back()->with('status', 'Two-factor authentication reset — the user must re-enroll.');
    }

    public function deactivate(string $user, Subjects $subjects): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $subjects->deactivate($this->resolve($user)->id);

        return back()->with('status', 'User deactivated — they can no longer sign in.');
    }

    public function reactivate(string $user, Subjects $subjects): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $subjects->reactivate($this->resolve($user)->id);

        return back()->with('status', 'User reactivated.');
    }

    public function revokeSession(string $user, string $session, SessionManager $sessions): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($user);

        // Only a session belonging to THIS env-scoped user (deny-by-default).
        if (Session::query()->whereKey($session)->where('user_id', $model->id)->exists()) {
            $sessions->revoke($session);
        }

        return back()->with('status', 'Session revoked.');
    }

    public function revokeAllSessions(string $user, SessionManager $sessions): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $sessions->revokeAllForUser($this->resolve($user)->id);

        return back()->with('status', 'All sessions revoked.');
    }

    public function assignOrganization(
        AssignUserOrganizationRequest $request,
        string $user,
        Memberships $memberships,
        OrgAccessRoles $catalog,
    ): RedirectResponse {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($user);

        $target = Organization::query()->whereKey($request->organizationId())->first();

        if (! $target instanceof Organization) {
            return back()->withErrors(['organization' => 'That organization is not in this environment.']);
        }

        /*
         * EXISTENCE IS NOT LIFE. This asked only whether the row was there, so a member
         * could be added to a SUSPENDED or DELETED organization — and the membership was
         * really written, granting access through an organization that refuses every
         * authenticated action. The picker no longer offers them, which is not the guard:
         * the id is a form field and a client can set it to anything.
         */
        $refusal = OrganizationAccess::refusalPhrase($target->status);

        if ($refusal !== null) {
            return back()->withErrors([
                'organization' => 'That organization has been '.$refusal.' and cannot take new members.',
            ]);
        }

        if ($memberships->of($target->id, $model->id) !== null) {
            return back()->withErrors(['organization' => 'The user is already a member of that organization.']);
        }

        // Belonging record (tier governs org administration + impersonation safety), then
        // the RBAC access roles that decide what the user can do in the apps.
        $memberships->add($target->id, $model->id, $request->role());

        // Segregation of duties refuses a toxic pair here exactly as it does on the Members
        // page. A grant withheld is not fatal — the rest of the assignment stands and the
        // governance screen reports what was not given.
        foreach ($request->accessRoleIds() as $roleId) {
            if ($catalog->isAssignable($target->id, $roleId)) {
                app(GrantAccessRole::class)->grant($target->id, $model->id, $roleId, GrantSource::Manual);
            }
        }

        return back()->with('status', 'User added to the organization.');
    }

    public function changeMembershipRole(
        Request $request,
        string $user,
        string $organization,
        Memberships $memberships,
    ): RedirectResponse {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($user);

        // Untrusted: an unassignable or unknown role is refused outright rather than
        // coerced to a default, and the refusal names the choices.
        $next = OrgRoles::parse($request->string('role')->toString());

        if ($next === null) {
            return back()->withErrors(['role' => OrgRoles::message()]);
        }

        if ($memberships->of($organization, $model->id) === null) {
            return back();
        }

        try {
            $memberships->changeRole($organization, $model->id, $next);
        } catch (LastOwner) {
            return back()->withErrors(['role' => 'An organization must keep at least one owner.']);
        }

        return back()->with('status', 'Org access updated.');
    }

    /**
     * Grant or revoke one RBAC access-role for this user in one organization.
     *
     * AN EXPLICIT SET rather than a toggle: a retried request and the checkbox must not
     * disagree about which state was asked for.
     */
    public function setAccessRole(
        Request $request,
        string $user,
        string $organization,
        Memberships $memberships,
        OrgAccessRoles $catalog,
    ): RedirectResponse {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($user);

        $roleId = $request->string('role')->toString();

        if ($memberships->of($organization, $model->id) === null || ! $catalog->isAssignable($organization, $roleId)) {
            return back();
        }

        if (! $request->boolean('granted')) {
            app(GrantAccessRole::class)->revoke($organization, $model->id, $roleId);

            return back()->with('status', 'Access role revoked.');
        }

        $refusal = app(GrantAccessRole::class)->grant($organization, $model->id, $roleId, GrantSource::Manual);

        if ($refusal !== null) {
            return back()->withErrors(['role' => $refusal->message()]);
        }

        return back()->with('status', 'Access role granted.');
    }

    /**
     * Grant or revoke a role EVERYWHERE in this environment.
     *
     * Deliberately NOT routed through {@see GrantAccessRole}, which is org-scoped by
     * construction — segregation of duties refuses a toxic PAIR within an organization, and
     * this grant belongs to none. It is still visible to that check: the framework's
     * `assignmentsForSubject()` unions environment-wide grants into what a person holds in
     * every organization, so the next org-scoped grant that would form a pair with this one
     * is refused there, where the pair actually exists.
     */
    public function setEnvironmentRole(
        Request $request,
        string $user,
        Roles $roles,
        OrgAccessRoles $catalog,
    ): RedirectResponse {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($user);

        $roleId = $request->string('role')->toString();

        // Only an environment-wide role — no organization, no declaring app. The framework
        // refuses anything else outright; asking here as well means the control is never
        // drawn for a role that would be rejected.
        if (! $catalog->isGrantableEverywhere($roleId)) {
            return back();
        }

        if (! $request->boolean('granted')) {
            $roles->unassignEverywhere($model->id, $roleId);

            return back()->with('status', 'Role removed everywhere.');
        }

        $roles->assignEverywhere($model->id, $roleId);

        return back()->with('status', 'Role granted in every organization.');
    }

    public function removeMembership(string $user, string $organization, Memberships $memberships): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($user);

        if ($memberships->of($organization, $model->id) === null) {
            return back();
        }

        try {
            $memberships->remove($organization, $model->id);
        } catch (LastOwner) {
            return back()->withErrors(['organization' => 'An organization must keep at least one owner.']);
        }

        return back()->with('status', 'Removed from the organization.');
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
