<?php

declare(strict_types=1);

use Cbox\Id\Organization\Enums\OrganizationStatus;
use App\Platform\OrganizationAccess;
use App\Mail\AdminAssignedPasswordMail;
use App\Mail\EmailVerificationMail;
use App\Mail\PasswordResetMail;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\GrantAccessRole;
use App\Platform\MailLinks;
use App\Platform\OrgAccessRoles;
use App\Platform\OrgRoles;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\EmailVerification;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\PasswordRevocationScope;
use Cbox\Id\Identity\Enums\UserStatus;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Identity\Rules\PasswordMeetsPolicy;
use Cbox\Id\Identity\ValueObjects\AdminPasswordAssignment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Exceptions\LastOwner;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Environment control plane › Users › detail. The full, deep-linkable lifecycle for
 * one end-user: profile, email verification, password reset, deactivate/reactivate,
 * organization memberships and support impersonation.
 *
 * Every mutation re-resolves the target within THIS environment (the User model's
 * BelongsToEnvironment scope) and 404s otherwise — an id from another plane never
 * matches (deny-by-default).
 *
 * THERE IS DELIBERATELY NO DELETE HERE. This page once carried one: it stripped the
 * memberships, called `$user->delete()`, and reported "User deleted." The schema
 * carries no foreign key on `user_id` anywhere, so nothing ever refused the delete and
 * the "they still have linked records" guard it was wrapped in could not fire. What
 * actually survived the row: sessions, passkeys, MFA factors and TOTP seeds, password
 * history, `identities.raw` (the person's whole IdP profile), magic links,
 * email-verification tokens, OAuth access/refresh tokens, `directory_users.resource`
 * (the whole SCIM payload) and role assignments. No domain event fired, so nothing
 * downstream was deprovisioned, and no audit entry recorded the act.
 *
 * An administrator being told an erasure happened when it did not is worse than having
 * no button at all — it retires the request. Deactivation is what this console can
 * honestly do, so deactivation is what it offers. Real erasure needs a designed
 * programme (ledger, grace window, downstream deprovisioning, crypto-shredded audit)
 * and must not be faked with a `->delete()`.
 */
new #[Layout('components.layouts.environment', ['title' => 'User'])] class extends Component
{
    /**
     * Second layer. The route's `env.admin` middleware is the primary gate and IS
     * re-run on Livewire actions (PersistentMiddlewareTest holds that), but this
     * console previously had NO in-component authorization at all — so when that
     * middleware was missing from the persistent list, every action here answered
     * unauthenticated. boot() rather than mount(): only boot() runs on each action.
     */
    public function boot(): void
    {
        abort_if(app(EnvironmentAdminAuth::class)->membership() === null, 403);
    }

    /** Whether the set-password panel is open. */
    public bool $settingPassword = false;

    public string $pwPassword = '';

    public string $pwReason = '';

    /** 'temporary' → must be changed at next sign-in; 'permanent' → stands as-is. */
    public string $pwMode = 'temporary';

    /** 'reveal' → shown once in the console; 'email' → sent to the user. */
    public string $pwDelivery = 'reveal';

    /** How much existing access the change cuts ({@see PasswordRevocationScope}). */
    public string $pwRevoke = 'sessions_and_tokens';

    /** Lifetime of a temporary password in hours; 0 = until changed. */
    public int $pwExpiryHours = 24;

    /**
     * The password just issued, held out of the wire snapshot (protected → never
     * dehydrated into the DOM) and surfaced once through the render.
     */
    protected ?string $issuedPassword = null;

    /**
     * WHOSE account this page is. LOCKED, so it is the route's answer and not the wire's.
     *
     * A plain public property is settable from the browser on every subsequent request,
     * so this component could be retargeted at any user in the environment after mount:
     * open your own detail page, post `userId=<somebody else>` alongside `setPassword`,
     * and the page acts on them. The route parameter is the only thing that should decide
     * whose account this is, and #[Locked] is what makes that true — Livewire refuses a
     * wire update that touches it.
     */
    #[Locked]
    public string $userId = '';

    public string $editName = '';

    public string $editEmail = '';

    public string $assignOrgId = '';

    public string $assignRole = 'member';

    /** @var list<string> Access-role ids to grant as the user is added to the org. */
    public array $assignAccessRoles = [];

    /** The org whose access-roles drawer is expanded, if any. */
    public ?string $managingOrgId = null;

    public function mount(string $user): void
    {
        $model = User::query()->whereKey($user)->first();
        abort_if($model === null, 404);

        $this->userId = $model->id;
        $this->editName = $model->name ?? '';
        $this->editEmail = $model->email;
    }

    /**
     * Demand a fresh credential before a takeover action, and say why on the screen.
     *
     * Returns true when the caller must stop: the redirect has already been issued and
     * where to come back to is recorded. The reason is per-action rather than one generic
     * sentence, because "this is a protected action" is what teaches people to type a
     * password without reading the page.
     */
    private function stepUpPending(string $reason): bool
    {
        $route = app(ConsoleStepUp::class)->challenge(
            'environment.users.show',
            'environment.users.show',
            ['user' => $this->userId],
            $reason,
        );

        if ($route === null) {
            return false;
        }

        $this->redirectRoute($route, navigate: false);

        return true;
    }

    private function user(): User
    {
        $model = User::query()->whereKey($this->userId)->first();
        abort_if($model === null, 404);

        return $model;
    }

    public function saveProfile(): void
    {
        $user = $this->user();

        $data = $this->validate([
            'editName' => ['nullable', 'string', 'max:190'],
            'editEmail' => ['required', 'email', 'max:190'],
        ]);

        $emailChanged = mb_strtolower($this->editEmail) !== mb_strtolower($user->email);

        if ($emailChanged && User::query()->where('email', $this->editEmail)->whereKeyNot($user->id)->exists()) {
            $this->addError('editEmail', 'Another user already uses that email in this environment.');

            return;
        }

        // Through the contract, so the change is audited as `user.updated` and emitted —
        // which is what makes it reach a webhook subscriber and the outbound SCIM push.
        // This was the last direct model write on this page: it left no record of who
        // changed the account's primary identifier, which is also its recovery channel.
        // The contract clears the verification on a changed email for us.
        app(Subjects::class)->update(
            $user->id,
            trim($this->editName) !== '' ? trim($this->editName) : '',
            $emailChanged ? $this->editEmail : null,
        );

        $this->dispatch('toast', message: 'Profile updated.');
    }

    public function suspend(Subjects $subjects): void
    {
        $subjects->deactivate($this->user()->id);
        $this->dispatch('toast', message: 'User deactivated — they can no longer sign in.');
    }

    public function reactivate(Subjects $subjects): void
    {
        $subjects->reactivate($this->user()->id);
        $this->dispatch('toast', message: 'User reactivated.');
    }

    /**
     * Set this user's password directly.
     *
     * Legitimate because this platform OWNS its user records — unlike a federation-only
     * service, we hold the credential, so administrative recovery is ours to perform.
     * It is only SAFE because it is gated on the env-admin capability (boot() above),
     * fully audited by the framework, and every consequence is an explicit choice rather
     * than a hidden default: temporary vs permanent, how it reaches the person, and how
     * much existing access it cuts.
     */
    public function setPassword(AdminPasswords $admin): void
    {
        $user = $this->user();

        $this->validate([
            // The environment's own policy, tightened by any organization this user
            // belongs to — an administrator is bound by the rules they set.
            'pwPassword' => ['required', 'string', 'max:200', PasswordMeetsPolicy::for($user->id)],
            'pwReason' => ['required', 'string', 'max:200'],
            // Public props: a crafted wire request can set anything, and the ::from()
            // below would throw ValueError rather than refuse the input.
            'pwMode' => ['required', 'in:temporary,permanent'],
            'pwDelivery' => ['required', 'in:reveal,email'],
            'pwRevoke' => ['required', Rule::enum(PasswordRevocationScope::class)],
            'pwExpiryHours' => ['required', 'integer', 'min:0', 'max:8760'],
        ], attributes: ['pwPassword' => 'password', 'pwReason' => 'reason']);

        // A FRESH PASSWORD BEFORE YOU CHOOSE SOMEBODY ELSE'S. This replaces any user in
        // the environment's credential and, with `reveal`, hands the plaintext straight
        // back — the most complete takeover this console offers. The vault and the
        // legacy-login page two screens away have demanded a step-up since the planes
        // merged, on the reasoning that the more privileged door should not be the one
        // without one. This was that door: a browser left open on a desk was the attack.
        //
        // AFTER validation, deliberately. A step-up in front of a shape check answers
        // garbage input with a password prompt instead of an error message, which trains
        // people to type their password at a screen they have not read — and hands
        // somebody probing the form a credential challenge rather than a refusal. The
        // authorization checks that DO come first are in boot(); this is the last gate
        // before the write, and nothing gets past it.
        if ($this->stepUpPending('Setting a password replaces this person’s credential; with “reveal” you are shown it.')) {
            return;
        }

        $temporary = $this->pwMode === 'temporary';
        $expiresAt = $temporary && $this->pwExpiryHours > 0
            ? now()->addHours($this->pwExpiryHours)
            : null;

        $actor = app(EnvironmentAdminAuth::class)->membership();

        $admin->assign(new AdminPasswordAssignment(
            userId: $user->id,
            password: $this->pwPassword,
            temporary: $temporary,
            expiresAt: $expiresAt,
            revoke: PasswordRevocationScope::from($this->pwRevoke),
            actorType: 'account_member',
            actorId: $actor?->id,
            reason: $this->pwReason,
        ));

        if ($this->pwDelivery === 'email') {
            Mail::to($user->email)->send(new AdminAssignedPasswordMail(
                password: $this->pwPassword,
                temporary: $temporary,
                expiresAt: $expiresAt?->toDayDateTimeString(),
            ));

            $this->dispatch('toast', message: 'Password set and emailed to '.$user->email.'.');
        } else {
            // Held in a PROTECTED prop so the credential is rendered once and never
            // dehydrated into the wire:snapshot in the DOM.
            $this->issuedPassword = $this->pwPassword;
            $this->dispatch('toast', message: 'Password set. Copy it now — it is shown once.');
        }

        $this->reset('pwPassword', 'pwReason', 'settingPassword');
    }

    /** Suggest a strong password so an admin never invents a weak one by hand. */
    public function generatePassword(): void
    {
        $this->pwPassword = Str::password(20, symbols: false);
    }

    public function dismissIssuedPassword(): void
    {
        $this->issuedPassword = null;
    }

    public function sendPasswordReset(PasswordReset $resets, MailLinks $links): void
    {
        $user = $this->user();

        $token = $resets->request($user->email);
        if (is_string($token)) {
            Mail::to($user->email)->send(new PasswordResetMail($links->route('password.reset', $token)));
        }

        $this->dispatch('toast', message: 'Password reset email sent to '.$user->email.'.');
    }

    public function resendVerification(EmailVerification $verification, MailLinks $links): void
    {
        $user = $this->user();
        if ($user->email_verified_at !== null) {
            return;
        }

        $token = $verification->issue($user->id, $user->email);
        Mail::to($user->email)->send(new EmailVerificationMail($links->route('verification.verify', $token)));

        $this->dispatch('toast', message: 'Verification email sent to '.$user->email.'.');
    }

    public function markVerified(Subjects $subjects): void
    {
        // Marking an address verified is what lets it be used to recover the account, so
        // it is a takeover with one more step rather than a lesser action.
        if ($this->stepUpPending('Marking this address verified lets it be used to recover this user’s sign-in.')) {
            return;
        }

        $user = $this->user();
        $subjects->markEmailVerified($user->id, $user->email);
        $this->dispatch('toast', message: 'Email marked as verified.');
    }

    public function resetMfa(Mfa $mfa): void
    {
        // Taking away someone's second factor leaves their password the only thing
        // between an attacker and the account — the step that makes the two above worth
        // doing.
        if ($this->stepUpPending('Resetting two-factor leaves this user protected by their password alone.')) {
            return;
        }

        // Through the contract, so the reset is audited as `user.mfa_disabled`. This
        // used to delete the rows directly — an admin taking away someone's second
        // factor was the one MFA action in the console that left no trace.
        $mfa->disable($this->user()->id);
        $this->dispatch('toast', message: 'Two-factor authentication reset — the user must re-enroll.');
    }

    public function revokeSession(string $sessionId, SessionManager $sessions): void
    {
        // Only a session belonging to THIS env-scoped user (deny-by-default).
        if (Session::query()->whereKey($sessionId)->where('user_id', $this->user()->id)->exists()) {
            $sessions->revoke($sessionId);
            $this->dispatch('toast', message: 'Session revoked.');
        }
    }

    public function revokeAllSessions(SessionManager $sessions): void
    {
        $sessions->revokeAllForUser($this->user()->id);
        $this->dispatch('toast', message: 'All sessions revoked.');
    }

    public function assignOrg(Memberships $memberships, Roles $roles, OrgAccessRoles $catalog): void
    {
        $user = $this->user();

        $this->validate([
            'assignOrgId' => ['required', 'string'],
            'assignRole' => ['required', OrgRoles::rule()],
            'assignAccessRoles' => ['array'],
            'assignAccessRoles.*' => ['string'],
        ], ['assignRole' => OrgRoles::message()]);

        $target = Organization::query()->whereKey($this->assignOrgId)->first();

        if (! $target instanceof Organization) {
            $this->addError('assignOrgId', 'That organization is not in this environment.');

            return;
        }

        // EXISTENCE IS NOT LIFE. This asked only whether the row was there, so a member
        // could be added to a SUSPENDED or DELETED organization — and the membership was
        // really written, granting access through an organization that refuses every
        // authenticated action. The picker below no longer offers them, which is not the
        // guard: `assignOrgId` is a Livewire property and a client can set it to anything.
        $refusal = OrganizationAccess::refusalPhrase($target->status);

        if ($refusal !== null) {
            $this->addError('assignOrgId', 'That organization has been '.$refusal.' and cannot take new members.');

            return;
        }

        if ($memberships->of($this->assignOrgId, $user->id) !== null) {
            $this->addError('assignOrgId', 'The user is already a member of that organization.');

            return;
        }

        // Belonging record (tier governs org administration + impersonation safety),
        // then the RBAC access roles that decide what the user can do in the apps.
        // Safe to parse rather than tryFrom: the rule above is derived from the same
        // assignable set, so a value that reached here is a case of the enum.
        $memberships->add($this->assignOrgId, $user->id, MembershipRole::from($this->assignRole));

        // Segregation of duties refuses a toxic pair here exactly as it does on the
        // Members page. A grant withheld is not fatal — the rest of the assignment
        // stands and the governance screen reports what was not given.
        foreach ($this->assignAccessRoles as $roleId) {
            if ($catalog->isAssignable($this->assignOrgId, $roleId)) {
                app(GrantAccessRole::class)->grant($this->assignOrgId, $user->id, $roleId, GrantSource::Manual);
            }
        }

        $this->assignOrgId = '';
        $this->assignRole = 'member';
        $this->assignAccessRoles = [];
        $this->dispatch('toast', message: 'User added to the organization.');
    }

    public function changeMembershipRole(string $orgId, string $role, Memberships $memberships): void
    {
        $user = $this->user();

        // Invoked from JS with the <select>'s value, so the role is untrusted and there
        // is no form field to report into: an unassignable or unknown role is refused
        // outright rather than coerced to a default.
        $next = OrgRoles::parse($role);

        if ($next === null || $memberships->of($orgId, $user->id) === null) {
            return;
        }

        try {
            $memberships->changeRole($orgId, $user->id, $next);
            $this->dispatch('toast', message: 'Org access updated.');
        } catch (LastOwner) {
            $this->dispatch('toast', message: 'An organization must keep at least one owner.', severity: 'error');
        }
    }

    public function toggleManageOrg(string $orgId): void
    {
        $this->managingOrgId = $this->managingOrgId === $orgId ? null : $orgId;
    }

    /** Grant or revoke one RBAC access-role for this user in the given org. */
    public function toggleAccessRole(string $orgId, string $roleId, Roles $roles, Memberships $memberships, OrgAccessRoles $catalog): void
    {
        $user = $this->user();

        if ($memberships->of($orgId, $user->id) === null || ! $catalog->isAssignable($orgId, $roleId)) {
            return;
        }

        $held = RoleAssignment::query()
            ->where('organization_id', $orgId)
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->exists();

        if ($held) {
            app(GrantAccessRole::class)->revoke($orgId, $user->id, $roleId);

            return;
        }

        $refusal = app(GrantAccessRole::class)->grant($orgId, $user->id, $roleId, GrantSource::Manual);

        if ($refusal !== null) {
            $this->dispatch('toast', message: $refusal->message(), severity: 'error');
        }
    }

    /**
     * Grant or revoke a role EVERYWHERE in this environment.
     *
     * The one grant that needs no organization, and the reason it exists: a support agent
     * who acts across every customer, somebody who has joined no organization, and any
     * app with no tenancy of its own to hang a grant on. Before this the only way to give
     * such a person anything was to invent a membership for them.
     *
     * Deliberately NOT routed through {@see GrantAccessRole}, which is org-scoped by
     * construction — segregation of duties refuses a toxic PAIR within an organization,
     * and this grant belongs to none. It is still visible to that check: the framework's
     * `assignmentsForSubject()` unions environment-wide grants into what a person holds
     * in every organization, so the next org-scoped grant that would form a pair with
     * this one is refused there, where the pair actually exists.
     */
    public function toggleEnvironmentRole(string $roleId, Roles $roles, OrgAccessRoles $catalog): void
    {
        $user = $this->user();

        // Only an environment-wide role — no organization, no declaring app. The
        // framework refuses anything else outright; asking here as well means the button
        // is never drawn for a role that would be rejected.
        if (! $catalog->isGrantableEverywhere($roleId)) {
            return;
        }

        if (in_array($roleId, $roles->everywhereFor($user->id), true)) {
            $roles->unassignEverywhere($user->id, $roleId);
            $this->dispatch('toast', message: 'Role removed everywhere.');

            return;
        }

        $roles->assignEverywhere($user->id, $roleId);
        $this->dispatch('toast', message: 'Role granted in every organization.');
    }

    public function removeMembership(string $orgId, Memberships $memberships): void
    {
        $user = $this->user();
        if ($memberships->of($orgId, $user->id) === null) {
            return;
        }

        try {
            $memberships->remove($orgId, $user->id);
            $this->dispatch('toast', message: 'Removed from the organization.');
        } catch (LastOwner) {
            $this->dispatch('toast', message: 'An organization must keep at least one owner.', severity: 'error');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Memberships $memberships, OrgAccessRoles $catalog): array
    {
        $user = $this->user();

        // Names for the memberships this user already holds — every organization,
        // whatever its status, because a membership of a suspended one still has to be
        // legible on this page rather than showing a bare id.
        /** @var Collection<string, string> $orgNames */
        $orgNames = Organization::query()->orderBy('name')->pluck('name', 'id');

        // What may be JOINED is a narrower question. Offering a deleted organization in
        // the picker invites exactly the thing the guard in assignOrg() now refuses.
        /** @var Collection<string, string> $joinableOrgs */
        $joinableOrgs = Organization::query()
            ->where('status', OrganizationStatus::Active)
            ->orderBy('name')
            ->pluck('name', 'id');

        $rows = [];
        $impersonatable = [];
        $orgCatalog = [];
        foreach ($memberships->forUser($user->id) as $m) {
            $rows[] = ['org' => $m->organization_id, 'orgName' => $orgNames[$m->organization_id] ?? $m->organization_id, 'role' => $m->role];
            if (! $m->role->canManageOrganization()) {
                $impersonatable[] = ['org' => $m->organization_id, 'orgName' => $orgNames[$m->organization_id] ?? $m->organization_id];
            }

            // Per-org RBAC access-role catalog + what this user holds there. Roles are
            // largely environment-wide, but app-declared roles are scoped per org.
            $roles = $catalog->assignable($m->organization_id);
            $orgCatalog[$m->organization_id] = [
                'roles' => $roles,
                'rolesById' => $roles->keyBy('id'),
                'appNames' => $catalog->appNames($roles),
                'permsByRole' => $catalog->permissions($roles),
                'assigned' => $catalog->assignedTo($m->organization_id, $user->id),
            ];
        }

        return [
            'user' => $user,
            // Surfaced through render, not a public prop, so a just-issued credential is
            // shown once and never dehydrated into the DOM snapshot.
            'issuedPassword' => $this->issuedPassword,
            'requiresPasswordChange' => app(AdminPasswords::class)->requiresChange($user->id),
            // Granted with no organization at all — the case an org-scoped grant cannot
            // describe. Read here so the page can say what a person holds everywhere
            // even when they belong to no organization, which is exactly when it matters.
            'everywhereRoles' => $catalog->grantableEverywhere(),
            'heldEverywhere' => app(Roles::class)->everywhereFor($user->id),
            'allOrgs' => $joinableOrgs,
            'orgNames' => $orgNames,
            'memberships' => $rows,
            'orgCatalog' => $orgCatalog,
            'assignableForNewOrg' => $this->assignOrgId !== '' ? $catalog->assignable($this->assignOrgId) : collect(),
            'assignableForNewOrgApps' => $this->assignOrgId !== '' ? $catalog->appNames($catalog->assignable($this->assignOrgId)) : [],
            'impersonatableOrgs' => $impersonatable,
            // Offered unless the person owns or administers something. A user with no
            // membership at all is impersonatable — the session simply names no
            // organization, which is what their own sign-in does too.
            'canImpersonate' => $rows === [] || $impersonatable !== [],
            'assignableRoles' => OrgRoles::assignable(),
            'hasMfa' => app(Mfa::class)->hasConfirmedTotp($user->id),
            'sessions' => Session::query()
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('last_active_at')
                ->limit(50)
                ->get(),
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route('environment.users') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Users</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $user->name ?? $user->email }}</h1>
            @unless ($user->email_verified_at)
                <span class="badge badge-warn">Unverified</span>
            @endunless
            @php $statusVariant = match ($user->status) { UserStatus::Active => 'badge-success', UserStatus::Disabled => 'badge-warn', UserStatus::Locked => 'badge-danger', default => '' }; @endphp
            <span class="badge {{ $statusVariant }}">{{ $user->status->value }}</span>
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $user->id }}</p>
    </div>

    {{-- Profile --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Profile</h2>
        <form wire:submit="saveProfile" class="mt-4 grid sm:grid-cols-[1fr_1fr_auto] gap-2 items-start">
            <div>
                <label class="label" for="editName">Name</label>
                <input wire:model="editName" id="editName" type="text" class="input" placeholder="Full name">
                @error('editName') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="editEmail">Email</label>
                <input wire:model="editEmail" id="editEmail" type="email" class="input">
                @error('editEmail') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary shrink-0 self-end" wire:loading.attr="disabled" wire:target="saveProfile">Save</button>
        </form>
    </div>

    {{-- Security & lifecycle --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Security &amp; lifecycle</h2>
        @if ($requiresPasswordChange)
            <p class="mt-2 text-sm" style="color:var(--muted)">This user is held at a password change — they cannot reach anything until they replace the one you issued.</p>
        @endif

        {{-- One-time reveal of a just-issued password. --}}
        @if ($issuedPassword)
            <div class="mt-4 rounded-lg p-4" style="border:1px solid color-mix(in srgb, var(--warning) 40%, transparent);background:color-mix(in srgb, var(--warning) 8%, transparent)">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="font-semibold text-sm" style="color:var(--warning-strong)">Copy this password now — it won't be shown again.</p>
                        <p class="mt-3 select-all break-all mono text-sm">{{ $issuedPassword }}</p>
                        <p class="mt-3 text-xs" style="color:var(--faint)">Hand it to {{ $user->email }} over a channel you trust. We never store it in readable form.</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <x-copy-button :value="$issuedPassword" class="btn-primary" />
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="dismissIssuedPassword">Dismiss</button>
                    </div>
                </div>
            </div>
        @endif

        {{-- Set a password directly. We own these user records, so administrative
             recovery is ours to perform — gated, audited, and every consequence chosen. --}}
        @if ($settingPassword)
            <form wire:submit="setPassword" class="mt-4 rounded-lg border p-4 space-y-4" style="border-color:var(--border)">
                <div>
                    <label for="pw-password" class="text-sm font-medium">New password</label>
                    <div class="mt-1.5 flex gap-2">
                        <input id="pw-password" wire:model="pwPassword" type="text" class="input mono" autocomplete="off"
                               @error('pwPassword') aria-invalid="true" aria-describedby="pw-password-error" @enderror>
                        <button type="button" class="btn btn-ghost btn-sm shrink-0" wire:click="generatePassword">Generate</button>
                    </div>
                    @error('pwPassword') <p id="pw-password-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label for="pw-mode" class="text-sm font-medium">Type</label>
                        <select id="pw-mode" wire:model.live="pwMode" class="input mt-1.5">
                            <option value="temporary">Temporary — they must change it at next sign-in</option>
                            <option value="permanent">Permanent — stands until they change it</option>
                        </select>
                    </div>
                    @if ($pwMode === 'temporary')
                        <div>
                            <label for="pw-expiry" class="text-sm font-medium">Valid for</label>
                            <select id="pw-expiry" wire:model="pwExpiryHours" class="input mt-1.5">
                                <option value="1">1 hour</option>
                                <option value="24">24 hours</option>
                                <option value="72">3 days</option>
                                <option value="0">Until they change it</option>
                            </select>
                        </div>
                    @endif
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label for="pw-delivery" class="text-sm font-medium">How they get it</label>
                        <select id="pw-delivery" wire:model="pwDelivery" class="input mt-1.5">
                            <option value="reveal">Show me once — I'll pass it on</option>
                            <option value="email">Email it to {{ $user->email }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="pw-revoke" class="text-sm font-medium">Existing access</label>
                        <select id="pw-revoke" wire:model="pwRevoke" class="input mt-1.5">
                            <option value="sessions_and_tokens">Sign out everywhere and revoke API tokens</option>
                            <option value="sessions_only">Sign out everywhere, keep API tokens</option>
                            <option value="nothing">Leave existing sessions alone</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="pw-reason" class="text-sm font-medium">Reason</label>
                    <input id="pw-reason" wire:model="pwReason" type="text" maxlength="200" class="input mt-1.5"
                           placeholder="e.g. Locked out after losing their phone"
                           @error('pwReason') aria-invalid="true" aria-describedby="pw-reason-error" @enderror>
                    @error('pwReason') <p id="pw-reason-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                    <p class="mt-1.5 text-xs" style="color:var(--faint)">Recorded on the audit trail alongside your name.</p>
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="setPassword">Set password</button>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('settingPassword', false)">Cancel</button>
                </div>
            </form>
        @endif

        <div class="mt-4 flex flex-wrap gap-2">
            {{-- Sends mail: without a busy state a double-click sent two reset links. --}}
            <button type="button" class="btn btn-ghost btn-sm" wire:click="sendPasswordReset"
                    wire:loading.attr="disabled" wire:target="sendPasswordReset">
                <span wire:loading.remove wire:target="sendPasswordReset">Send password reset</span>
                <span wire:loading wire:target="sendPasswordReset" class="inline-flex items-center gap-2"><span class="spinner"></span> Sending…</span>
            </button>
            @unless ($settingPassword)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('settingPassword', true)">Set password…</button>
            @endunless
            @unless ($user->email_verified_at)
                {{-- Sends mail: without a busy state a double-click sent two verification mails. --}}
                <button type="button" class="btn btn-ghost btn-sm" wire:click="resendVerification"
                        wire:loading.attr="disabled" wire:target="resendVerification">
                    <span wire:loading.remove wire:target="resendVerification">Resend verification</span>
                    <span wire:loading wire:target="resendVerification" class="inline-flex items-center gap-2"><span class="spinner"></span> Sending…</span>
                </button>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="markVerified">Mark verified</button>
            @endunless
            @if ($hasMfa)
                <x-confirm-delete
                    :name="$user->email"
                    action="resetMfa"
                    label="Reset 2FA"
                    verb="Reset 2FA for"
                    trigger-class="btn btn-ghost btn-sm"
                    consequence="This destroys the user's enrolled second factor and their recovery codes. Until they enrol again the account is protected by its password alone." />
            @endif
            @if ($user->status === UserStatus::Active)
                <x-confirm-delete
                    :name="$user->email"
                    action="suspend"
                    label="Deactivate"
                    trigger-class="btn btn-ghost btn-sm"
                    consequence="This user can no longer sign in to any application in this environment, and their existing sessions stop working on their next request. Their records are kept and you can reactivate them at any time." />
            @else
                <button type="button" class="btn btn-ghost btn-sm" wire:click="reactivate">Reactivate</button>
            @endif
        </div>
        <p class="mt-2 text-xs" style="color:var(--faint)">Two-factor: {{ $hasMfa ? 'enabled' : 'not enrolled' }}.</p>

        {{-- Says what the console does NOT do. The delete button that used to sit above
             reported success without erasing anything (see the class docblock); an
             administrator who believes an erasure happened stops pursuing it, which is
             the worse failure. --}}
        <div class="mt-4 rounded-lg p-3 text-xs" style="border:1px solid var(--border);color:var(--muted)">
            <p><b>Deactivation is the only off-switch here — there is no delete.</b></p>
            <p class="mt-1.5">
                Deactivating stops all sign-in but keeps the person's records: sessions,
                passkeys and second factors, identity-provider profiles, directory data,
                issued tokens, role assignments and audit history all remain.
            </p>
            <p class="mt-1.5">
                Erasing a person is not implemented in this platform. A right-to-erasure
                request has to be handled outside the console until it is.
            </p>
        </div>
    </div>

    {{-- Active sessions --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <div class="flex items-center justify-between gap-4">
            <h2 class="cbx-section-title">Active sessions</h2>
            @if ($sessions->isNotEmpty())
                <x-confirm-delete
                    :name="$user->email"
                    action="revokeAllSessions"
                    label="Revoke all"
                    consequence="Every one of this user's sessions is terminated immediately and they are signed out on all devices." />
            @endif
        </div>
        <div class="mt-4 space-y-2">
            @forelse ($sessions as $s)
                <div class="flex items-center gap-3 rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="session-{{ $s->id }}">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm truncate">{{ $s->user_agent ?? 'Unknown device' }}</p>
                        <p class="text-xs truncate" style="color:var(--faint)">{{ $s->ip ?? '—' }} · {{ $s->last_active_at?->diffForHumans() ?? 'never' }}@if (in_array('impersonation', $s->amr, true)) · <span style="color:var(--accent-strong)">impersonation</span>@endif</p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)" wire:click="revokeSession('{{ $s->id }}')" wire:confirm="Revoke this session?">Revoke</button>
                </div>
            @empty
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="shield" class="w-5 h-5" /></div>
                    <h3>No active sessions</h3>
                    <p>This user has no signed-in sessions right now. They appear here once the user signs in.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Organizations --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Organizations</h2>
        <p class="mt-1 text-sm" style="color:var(--muted)"><b>Org access</b> is the user's administration level; <b>access roles</b> are what they can do inside that org's apps.</p>
        <div class="mt-4 space-y-2">
            @forelse ($memberships as $m)
                @php $cat = $orgCatalog[$m['org']] ?? ['roles' => collect(), 'rolesById' => collect(), 'appNames' => [], 'permsByRole' => [], 'assigned' => []]; @endphp
                <div class="rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="mem-{{ $m['org'] }}">
                    <div class="flex items-center gap-2">
                        <a href="{{ route('environment.organizations.show', $m['org']) }}" class="min-w-0 flex-1 truncate text-sm font-medium" style="color:var(--accent-strong)">{{ $m['orgName'] }}</a>
                        {{-- Explicit save, NOT wire:change: on a focused select a stray arrow-key
                             fires `change` and silently demoted an Owner with no way back. --}}
                        <div class="flex items-center gap-1.5 shrink-0" x-data="{ saved: @js($m['role']->value), val: @js($m['role']->value), busy: false }">
                            <select class="select" style="width:auto" aria-label="Org access in {{ $m['orgName'] }}" x-model="val">
                                @foreach ($assignableRoles as $role)
                                    <option value="{{ $role->value }}" @selected($m['role'] === $role)>{{ $role->label() }}</option>
                                @endforeach
                            </select>
                            <button type="button" class="btn btn-primary btn-sm shrink-0" x-cloak x-show="val !== saved" :disabled="busy"
                                    x-text="busy ? 'Saving…' : 'Save'"
                                    @click="busy = true; $wire.changeMembershipRole('{{ $m['org'] }}', val).then(() => { saved = val; busy = false })"></button>
                        </div>
                        @php $removeMembershipAction = "removeMembership('{$m['org']}')"; @endphp
                        <x-confirm-delete
                            :name="$user->email"
                            :action="$removeMembershipAction"
                            label="Remove"
                            verb="Remove membership for"
                            trigger-class="btn btn-ghost btn-sm shrink-0"
                            trigger-style="color:var(--destructive)"
                            consequence="They lose every role this organization grants them, immediately." />
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                        <span class="text-xs" style="color:var(--faint)">Access roles:</span>
                        @forelse ($cat['assigned'] as $rid)
                            @php $r = $cat['rolesById'][$rid] ?? null; @endphp
                            @if ($r)<span class="badge">{{ $r->name }}</span>@endif
                        @empty
                            <span class="text-xs" style="color:var(--faint)">None</span>
                        @endforelse
                        @if ($cat['roles']->isNotEmpty())
                            <button type="button" wire:click="toggleManageOrg('{{ $m['org'] }}')" class="btn btn-ghost btn-sm" style="height:24px;padding:0 8px;font-size:11px">{{ $managingOrgId === $m['org'] ? 'Done' : 'Manage' }}</button>
                        @endif
                    </div>
                    @if ($managingOrgId === $m['org'])
                        <div class="mt-3 rounded-lg p-3" style="background:color-mix(in oklch, var(--secondary) 55%, transparent)">
                            <x-access-roles-manager :roles="$cat['roles']" :app-names="$cat['appNames']" :perms-by-role="$cat['permsByRole']" :assigned="$cat['assigned']" toggle="toggleAccessRole" :arg="$m['org']" :subject="$user->name ?? $user->email" />
                        </div>
                    @endif
                </div>
            @empty
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="layers" class="w-5 h-5" /></div>
                    <h3>Not a member of any organization</h3>
                    <p>
                        Add them to one below to grant access inside it — or, if your apps
                        have no tenancy of their own, give them a role that applies
                        everywhere in this environment.
                    </p>
                </div>
            @endforelse
        </div>
        {{-- GRANTS THAT NAME NO ORGANIZATION. Every grant above is scoped to one tenant,
             which cannot describe a support agent acting across all of them, somebody who
             has joined none, or an app with no tenancy of its own. Those people used to
             get a token with no roles and no permissions and there was no way to give
             them any. --}}
        @if ($everywhereRoles->isNotEmpty())
            <div class="mt-4 rounded-lg border p-3" style="border-color:var(--border)">
                <p class="text-sm font-medium">Roles everywhere in this environment</p>
                <p class="mt-1 text-xs" style="color:var(--muted)">
                    Applied in <b>every</b> organization, and to this person even when they
                    belong to none. Only roles you defined for the whole environment can be
                    granted this way — one organization's own role is their policy, not
                    everyone's.
                </p>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach ($everywhereRoles as $envRole)
                        @php $isHeld = in_array($envRole->id, $heldEverywhere, true); @endphp
                        <button type="button" wire:key="envrole-{{ $envRole->id }}"
                                wire:click="toggleEnvironmentRole('{{ $envRole->id }}')"
                                class="btn btn-sm {{ $isHeld ? 'btn-primary' : 'btn-ghost' }}"
                                aria-pressed="{{ $isHeld ? 'true' : 'false' }}">
                            {{ $envRole->name }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        <form wire:submit="assignOrg" class="mt-4 space-y-3">
            <div class="grid sm:grid-cols-[1fr_auto_auto] gap-2 items-start">
                <div>
                    <select wire:model.live="assignOrgId" class="select" aria-label="Organization">
                        <option value="">Add to organization…</option>
                        @foreach ($allOrgs as $orgId => $orgName)
                            <option value="{{ $orgId }}">{{ $orgName }}</option>
                        @endforeach
                    </select>
                    @error('assignOrgId') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <select wire:model="assignRole" class="select" aria-label="Org access">
                        @foreach ($assignableRoles as $role)
                            <option value="{{ $role->value }}">{{ $role->label() }}</option>
                        @endforeach
                    </select>
                    @error('assignRole') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary shrink-0" wire:loading.attr="disabled" wire:target="assignOrg">Add</button>
            </div>
            @if ($assignOrgId !== '')
                <x-access-roles-field :roles="$assignableForNewOrg" :app-names="$assignableForNewOrgApps" model="assignAccessRoles" hint="granted immediately (optional)" />
            @endif
        </form>
    </div>

    {{-- Support impersonation (full request — changes the session) --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Support impersonation</h2>
        {{-- NO MEMBERSHIP REQUIRED. This offered a picker of the user's organizations and,
             when they had none, told the administrator to invent one — in an environment
             that may not use organizations at all, which is where support is needed just
             as much. Without one the session simply names no organization, exactly as an
             ordinary sign-in by that person would. --}}
        @if ($canImpersonate)
            <form method="POST" action="{{ route('environment.impersonate', $user->id) }}" class="mt-4 grid sm:grid-cols-[1fr_1fr_auto] gap-2 items-start">
                @csrf
                @if ($impersonatableOrgs !== [])
                    <select name="organization" class="select" required aria-label="Organization">
                        @foreach ($impersonatableOrgs as $io)
                            <option value="{{ $io['org'] }}">{{ $io['orgName'] }}</option>
                        @endforeach
                    </select>
                @else
                    <p class="text-sm self-center" style="color:var(--muted)">Signed in as themselves, in no organization.</p>
                @endif
                <input name="reason" type="text" class="input" placeholder="Reason (required)" maxlength="200" required aria-label="Reason">
                <button type="submit" class="btn btn-ghost btn-sm shrink-0" x-on:click="if (! window.confirm('Step into this user\'s session for support? It is time-boxed and fully audited.')) $event.preventDefault()">Impersonate</button>
            </form>
            <p class="mt-2 text-xs" style="color:var(--faint)">Time-boxed to 30 minutes and recorded on the audit trail.</p>
        @else
            <p class="mt-2 text-sm" style="color:var(--muted)">This user owns or administers an organization, and those cannot be impersonated — stepping into one would hand durable control of a tenant to whoever did it.</p>
        @endif
    </div>
</div>
