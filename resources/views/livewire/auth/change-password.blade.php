<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Rules\PasswordMeetsPolicy;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * The forced password change.
 *
 * An administrator handing out a temporary password is promising that the recipient
 * must replace it — otherwise "temporary" describes nothing, and a credential the
 * administrator knows becomes a permanent second way in. That promise is kept here:
 * {@see \App\Http\Middleware\Authenticate} holds every authenticated request on this
 * page until the requirement is cleared, so the change cannot be walked around by
 * typing a different URL.
 *
 * The current password is deliberately NOT asked for. The person just proved it to
 * reach an authenticated session, and asking again would only tempt someone who was
 * handed a password by their administrator into writing it down.
 */
new #[Layout('components.layouts.auth', ['title' => 'Choose a new password'])] class extends Component
{
    public string $password = '';

    public string $passwordConfirmation = '';

    public function save(Subjects $subjects, AdminPasswords $admin): void
    {
        $me = app(CurrentUser::class);

        $this->validate([
            'password' => ['required', 'string', 'max:200', PasswordMeetsPolicy::for($me->id())],
        ]);

        if ($this->password !== $this->passwordConfirmation) {
            $this->addError('passwordConfirmation', 'The passwords do not match.');

            return;
        }

        $subjects->setPassword($me->id(), $this->password);

        // Only now is the requirement satisfied — clearing it before the write would
        // release the hold on a password the policy might still refuse.
        $admin->clear($me->id());

        session()->flash('status', 'Your password has been updated.');
        $this->redirectRoute('dashboard', navigate: false);
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Choose a new password</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">
        The password you signed in with was issued by an administrator. Choose one only you know before continuing.
    </p>

    <form wire:submit="save" class="mt-7 space-y-4">
        <input type="hidden" name="email" value="{{ app(\App\Platform\CurrentUser::class)->email() }}" autocomplete="username">

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
