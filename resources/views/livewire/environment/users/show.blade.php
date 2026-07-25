<?php

declare(strict_types=1);

use App\Mail\AdminAssignedPasswordMail;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Enums\PasswordRevocationScope;
use Cbox\Id\Identity\ValueObjects\AdminPasswordAssignment;
use Illuminate\Support\Str;
use App\Platform\EnvironmentAdminAuth;
use App\Mail\EmailVerificationMail;
use App\Mail\PasswordResetMail;
use App\Platform\OrgAccessRoles;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Identity\Contracts\EmailVerification;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\UserStatus;
use Cbox\Id\Identity\Models\MfaFactor;
use Cbox\Id\Identity\Models\MfaRecoveryCode;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Identity\Rules\PasswordMeetsPolicy;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Exceptions\LastOwner;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Users › detail. The full, deep-linkable lifecycle for
 * one end-user: profile, email verification, password reset, activate/suspend/delete,
 * organization memberships and support impersonation.
 *
 * Every mutation re-resolves the target within THIS environment (the User model's
 * BelongsToEnvironment scope) and 404s otherwise — an id from another plane never
 * matches (deny-by-default).
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
        abort_if(app(EnvironmentAdminAuth::class)->current() === null, 403);
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

        $emailChanged = mb_strtolower($data['editEmail']) !== mb_strtolower($user->email);

        if ($emailChanged && User::query()->where('email', $data['editEmail'])->whereKeyNot($user->id)->exists()) {
            $this->addError('editEmail', 'Another user already uses that email in this environment.');

            return;
        }

        $user->name = trim($data['editName']) !== '' ? trim($data['editName']) : null;
        if ($emailChanged) {
            // A changed email is unverified until re-confirmed — never silently trust it.
            $user->email = $data['editEmail'];
            $user->email_verified_at = null;
        }
        $user->save();

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

    public function deleteUser(Memberships $memberships): mixed
    {
        $user = $this->user();

        try {
            foreach ($memberships->forUser($user->id) as $membership) {
                $memberships->remove($membership->organization_id, $user->id);
            }
            $user->delete();
        } catch (\Throwable) {
            $this->dispatch('toast', message: 'Could not delete this user (they still have linked records) — deactivate instead.', severity: 'error');

            return null;
        }

        $this->dispatch('toast', message: 'User deleted.');

        return $this->redirectRoute('environment.users', navigate: true);
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
        ], attributes: ['pwPassword' => 'password', 'pwReason' => 'reason']);

        $temporary = $this->pwMode === 'temporary';
        $expiresAt = $temporary && $this->pwExpiryHours > 0
            ? now()->addHours($this->pwExpiryHours)
            : null;

        $actor = app(EnvironmentAdminAuth::class)->current();

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

    public function sendPasswordReset(PasswordReset $resets): void
    {
        $user = $this->user();

        $token = $resets->request($user->email);
        if (is_string($token)) {
            Mail::to($user->email)->send(new PasswordResetMail(route('password.reset', $token)));
        }

        $this->dispatch('toast', message: 'Password reset email sent to '.$user->email.'.');
    }

    public function resendVerification(EmailVerification $verification): void
    {
        $user = $this->user();
        if ($user->email_verified_at !== null) {
            return;
        }

        $token = $verification->issue($user->id, $user->email);
        Mail::to($user->email)->send(new EmailVerificationMail(route('verification.verify', $token)));

        $this->dispatch('toast', message: 'Verification email sent to '.$user->email.'.');
    }

    public function markVerified(Subjects $subjects): void
    {
        $user = $this->user();
        $subjects->markEmailVerified($user->id, $user->email);
        $this->dispatch('toast', message: 'Email marked as verified.');
    }

    public function resetMfa(): void
    {
        $user = $this->user();
        // No disable verb on the contract; clearing the env-scoped factors + recovery
        // codes forces a fresh enrollment on next sign-in.
        MfaFactor::query()->where('user_id', $user->id)->delete();
        MfaRecoveryCode::query()->where('user_id', $user->id)->delete();
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
            'assignRole' => ['required', 'in:member,admin,owner'],
            'assignAccessRoles' => ['array'],
            'assignAccessRoles.*' => ['string'],
        ]);

        if (Organization::query()->whereKey($this->assignOrgId)->doesntExist()) {
            $this->addError('assignOrgId', 'That organization is not in this environment.');

            return;
        }

        if ($memberships->of($this->assignOrgId, $user->id) !== null) {
            $this->addError('assignOrgId', 'The user is already a member of that organization.');

            return;
        }

        // Belonging record (tier governs org administration + impersonation safety),
        // then the RBAC access roles that decide what the user can do in the apps.
        $memberships->add($this->assignOrgId, $user->id, $this->assignRole);

        foreach ($this->assignAccessRoles as $roleId) {
            if ($catalog->isAssignable($this->assignOrgId, $roleId)) {
                $roles->assign($this->assignOrgId, $user->id, $roleId, GrantSource::Manual);
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
        if (! in_array($role, ['member', 'admin', 'owner'], true) || $memberships->of($orgId, $user->id) === null) {
            return;
        }

        try {
            $memberships->changeRole($orgId, $user->id, $role);
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
            $roles->unassign($orgId, $user->id, $roleId);
        } else {
            $roles->assign($orgId, $user->id, $roleId, GrantSource::Manual);
        }
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

        /** @var \Illuminate\Support\Collection<string, string> $orgNames */
        $orgNames = Organization::query()->orderBy('name')->pluck('name', 'id');

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
            'allOrgs' => $orgNames,
            'memberships' => $rows,
            'orgCatalog' => $orgCatalog,
            'assignableForNewOrg' => $this->assignOrgId !== '' ? $catalog->assignable($this->assignOrgId) : collect(),
            'assignableForNewOrgApps' => $this->assignOrgId !== '' ? $catalog->appNames($catalog->assignable($this->assignOrgId)) : [],
            'impersonatableOrgs' => $impersonatable,
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
        <p class="text-sm font-medium">Profile</p>
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
        <p class="text-sm font-medium">Security &amp; lifecycle</p>
        @if ($requiresPasswordChange)
            <p class="mt-2 text-sm" style="color:var(--muted)">This user must choose a new password the next time they sign in.</p>
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
            <button type="button" class="btn btn-ghost btn-sm" wire:click="sendPasswordReset">Send password reset</button>
            @unless ($settingPassword)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('settingPassword', true)">Set password…</button>
            @endunless
            @unless ($user->email_verified_at)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="resendVerification">Resend verification</button>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="markVerified">Mark verified</button>
            @endunless
            @if ($hasMfa)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="resetMfa" wire:confirm="Reset this user's two-factor authentication? They must set it up again.">Reset 2FA</button>
            @endif
            @if ($user->status === UserStatus::Active)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="suspend" wire:confirm="Deactivate this user? They can no longer sign in.">Deactivate</button>
            @else
                <button type="button" class="btn btn-ghost btn-sm" wire:click="reactivate">Reactivate</button>
            @endif
            <button type="button" class="btn btn-ghost btn-sm" style="color:var(--destructive)" wire:click="deleteUser" wire:confirm="Permanently delete this user and their memberships? This cannot be undone.">Delete user</button>
        </div>
        <p class="mt-2 text-xs" style="color:var(--faint)">Two-factor: {{ $hasMfa ? 'enabled' : 'not enrolled' }}.</p>
    </div>

    {{-- Active sessions --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <div class="flex items-center justify-between gap-4">
            <p class="text-sm font-medium">Active sessions</p>
            @if ($sessions->isNotEmpty())
                <button type="button" class="btn btn-ghost btn-sm" style="color:var(--destructive)" wire:click="revokeAllSessions" wire:confirm="Sign this user out of all sessions?">Revoke all</button>
            @endif
        </div>
        <div class="mt-4 space-y-2">
            @forelse ($sessions as $s)
                <div class="flex items-center gap-3 rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="session-{{ $s->id }}">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm truncate">{{ $s->user_agent ?? 'Unknown device' }}</p>
                        <p class="text-xs truncate" style="color:var(--faint)">{{ $s->ip ?? '—' }} · {{ $s->last_active_at?->diffForHumans() ?? 'never' }}@if (in_array('impersonation', $s->amr, true)) · <span style="color:var(--accent)">impersonation</span>@endif</p>
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
        <p class="text-sm font-medium">Organizations</p>
        <p class="mt-1 text-sm" style="color:var(--muted)"><b>Org access</b> is the user's administration level; <b>access roles</b> are what they can do inside that org's apps.</p>
        <div class="mt-4 space-y-2">
            @forelse ($memberships as $m)
                @php $cat = $orgCatalog[$m['org']] ?? ['roles' => collect(), 'rolesById' => collect(), 'appNames' => [], 'permsByRole' => [], 'assigned' => []]; @endphp
                <div class="rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="mem-{{ $m['org'] }}">
                    <div class="flex items-center gap-2">
                        <a href="{{ route('environment.organizations.show', $m['org']) }}" class="min-w-0 flex-1 truncate text-sm font-medium" style="color:var(--accent)">{{ $m['orgName'] }}</a>
                        <select class="select" style="width:auto" aria-label="Org access" wire:change="changeMembershipRole('{{ $m['org'] }}', $event.target.value)">
                            @foreach (['member' => 'Member', 'admin' => 'Admin', 'owner' => 'Owner'] as $val => $lbl)
                                <option value="{{ $val }}" @selected($m['role']->value === $val)>{{ $lbl }}</option>
                            @endforeach
                        </select>
                        <button type="button" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)" wire:click="removeMembership('{{ $m['org'] }}')" wire:confirm="Remove from this organization?">Remove</button>
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
                    <p>Add this user to an organization below to grant them access to its apps.</p>
                </div>
            @endforelse
        </div>
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
                <select wire:model="assignRole" class="select" aria-label="Org access">
                    <option value="member">Member</option>
                    <option value="admin">Admin</option>
                    <option value="owner">Owner</option>
                </select>
                <button type="submit" class="btn btn-primary shrink-0" wire:loading.attr="disabled" wire:target="assignOrg">Add</button>
            </div>
            @if ($assignOrgId !== '')
                <x-access-roles-field :roles="$assignableForNewOrg" :app-names="$assignableForNewOrgApps" model="assignAccessRoles" hint="granted immediately (optional)" />
            @endif
        </form>
    </div>

    {{-- Support impersonation (full request — changes the session) --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Support impersonation</p>
        @if ($impersonatableOrgs !== [])
            <form method="POST" action="{{ route('environment.impersonate', $user->id) }}" class="mt-4 grid sm:grid-cols-[1fr_1fr_auto] gap-2 items-start">
                @csrf
                <select name="organization" class="select" required aria-label="Organization">
                    @foreach ($impersonatableOrgs as $io)
                        <option value="{{ $io['org'] }}">{{ $io['orgName'] }}</option>
                    @endforeach
                </select>
                <input name="reason" type="text" class="input" placeholder="Reason (required)" maxlength="200" required aria-label="Reason">
                <button type="submit" class="btn btn-ghost btn-sm shrink-0" x-on:click="if (! window.confirm('Step into this user\'s session for support? It is time-boxed and fully audited.')) $event.preventDefault()">Impersonate</button>
            </form>
            <p class="mt-2 text-xs" style="color:var(--faint)">Time-boxed to 30 minutes and recorded on the audit trail.</p>
        @else
            <p class="mt-2 text-sm" style="color:var(--muted)">Add the user to an organization as a member to enable support impersonation (owners and admins can't be impersonated).</p>
        @endif
    </div>
</div>
