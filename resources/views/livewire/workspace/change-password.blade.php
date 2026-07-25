<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Identity\Rules\PasswordMeetsPolicy;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * The forced password change, account plane.
 *
 * The account-plane twin of {@see resources/views/livewire/auth/change-password.blade.php}.
 * It writes through `AccountMembers::resetPassword()` rather than touching the subject
 * directly, so the member row's fallback credential and security stamp move with it —
 * a change that left the stamp behind would keep every session minted under the
 * administrator's password alive.
 */
new #[Layout('components.layouts.auth', ['title' => 'Choose a new password'])] class extends Component
{
    public string $password = '';

    public string $passwordConfirmation = '';

    public ?string $email = null;

    public function mount(AccountAuth $auth): void
    {
        $this->email = $auth->current()?->email;
    }

    public function save(AccountAuth $auth, AccountMembers $members, AdminPasswords $admin, PlatformRoot $root): void
    {
        $member = $auth->current();

        if ($member === null) {
            $this->redirectRoute('workspace.login', navigate: false);

            return;
        }

        $subjectId = $member->subject_id;

        $this->validate([
            'password' => ['required', 'string', 'max:200', $root->run(fn () => PasswordMeetsPolicy::for($subjectId))],
        ]);

        if ($this->password !== $this->passwordConfirmation) {
            $this->addError('passwordConfirmation', 'The passwords do not match.');

            return;
        }

        $members->resetPassword($member->id, $this->password);

        if (is_string($subjectId)) {
            $root->run(fn () => $admin->clear($subjectId));
        }

        // resetPassword bumps the security stamp, which invalidates the session that
        // reached this page — sign them back in on the new credential rather than
        // bouncing them to a login form they just satisfied.
        $auth->establish($member->id);

        session()->flash('status', 'Your password has been updated.');
        $this->redirectRoute('workspace.home', navigate: false);
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Choose a new password</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">
        The password you signed in with was issued by an administrator. Choose one only you know before continuing.
    </p>

    <form wire:submit="save" class="mt-7 space-y-4">
        <input type="hidden" name="email" value="{{ $email }}" autocomplete="username">

        <div>
            <label class="label" for="password">New password</label>
            <input wire:model="password" id="password" name="password" type="password"
                   autocomplete="new-password" class="input input-lg" autofocus>
            @error('password') <p class="error-text" id="password-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="passwordConfirmation">Confirm new password</label>
            <input wire:model="passwordConfirmation" id="passwordConfirmation" name="passwordConfirmation"
                   type="password" autocomplete="new-password" class="input input-lg">
            @error('passwordConfirmation') <p class="error-text" id="passwordConfirmation-error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-full">Update password</button>
    </form>
</div>
