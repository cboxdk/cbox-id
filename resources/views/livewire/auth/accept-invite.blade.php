<?php

declare(strict_types=1);

use App\Platform\Enums\CredentialVerdict;
use App\Platform\Enums\RefusedFactor;
use App\Platform\PlatformAuth;
use App\Platform\SsoRefusal;
use App\Platform\SubjectCredentialGate;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Rules\PasswordMeetsPolicy;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Platform\PlatformRoot;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Accept an invitation: the invitee sets a password and is signed in.
 *
 * The page is reached only via a signed URL (route middleware), and the token is #[Locked]
 * so it cannot be swapped after that signed load — an attacker can neither forge the
 * signature nor point the action at a different invitation.
 *
 * IT REDEEMS AN INVITATION TOKEN NOW, not a member id. The account plane's invitation was a
 * member row in an `invited` state, so the link named the row and "accepting" meant
 * activating it. An invitation is its own record with its own single-use token, and the
 * subject it creates is an ordinary one — which is why the password rule below is asked of
 * the SUBJECT rather than of the invitation.
 */
new #[Layout('components.layouts.auth', ['title' => 'Accept invitation'])] class extends Component
{
    #[Locked]
    public string $token = '';

    public string $password = '';

    public ?string $email = null;

    public ?string $organizationName = null;

    public function mount(string $token, Invitations $invitations, Organizations $organizations, PlatformRoot $platformRoot): mixed
    {
        $this->token = $token;

        $invitation = $platformRoot->run(fn () => $invitations->byToken($token));

        // `isPending()`, not merely "was found". byToken() resolves a REDEEMED or revoked
        // invitation just as happily as a live one — it is a lookup by hash, not a
        // validity check — so without this the page renders for a spent link and asks the
        // visitor to choose a password before refusing them. A refusal that arrives after
        // the form is the shape people learn to distrust.
        if ($invitation === null || ! $invitation->isPending()) {
            return redirect()->route('login')
                ->with('status', 'This invitation is no longer valid. Try signing in.');
        }

        $this->email = $invitation->email;
        $this->organizationName = $platformRoot->run(
            fn () => $organizations->find($invitation->organization_id)?->name,
        );

        return null;
    }

    public function accept(
        Invitations $invitations,
        Subjects $subjects,
        SubjectCredentialGate $gate,
        PlatformAuth $platformAuth,
        PlatformRoot $platformRoot,
    ): void {
        $invitation = $platformRoot->run(fn () => $invitations->byToken($this->token));

        if ($invitation === null || ! $invitation->isPending()) {
            $this->redirect(route('login'), navigate: false);

            return;
        }

        $email = (string) $invitation->email;
        $existing = $platformRoot->run(fn () => $subjects->findByEmail($email));

        // Asked of the SUBJECT the password will belong to, so its reuse history applies
        // alongside the tenant's length and breach rules. A first-time invitee has no
        // subject yet, and the rule handles that.
        $this->validate([
            'password' => ['required', 'string', 'max:200', PasswordMeetsPolicy::for($existing?->id)],
        ]);

        $subject = $platformRoot->run(
            fn () => $existing ?? $subjects->create($email, null, $this->password),
        );

        if ($subject === null) {
            $this->redirect(route('login'), navigate: false);

            return;
        }

        // Single-use by the token itself, so a replayed or racing accept redeems nothing.
        $membership = $platformRoot->run(fn () => $invitations->accept($this->token, $subject->id));

        if ($membership === null) {
            $this->redirect(route('login'), navigate: false);

            return;
        }

        // The membership is REAL now and stays real — the invitation was genuine and this
        // person belongs here. What a mandate refuses is the SESSION: an invitation is an
        // emailed bearer token, and the organization has said email possession does not
        // open its console.
        //
        // After acceptance rather than before it, and the awkward part is deliberate. The
        // password they just chose will never sign them in, which reads as a wasted step —
        // but the membership is what an SSO assertion can later land on, so refusing
        // earlier would leave them permanently unable to enter by the very door they are
        // being sent to. Better a spare password than a dead end.
        if ($gate->admitsFactor($subject->id) === CredentialVerdict::SsoRequired) {
            SsoRefusal::hold($subject->id, RefusedFactor::Invitation);

            $this->redirect(route('login'), navigate: false);

            return;
        }

        $platformRoot->run(fn () => $platformAuth->establish(request(), $subject->id, ['invitation']));

        $this->redirect(route('projects'), navigate: false);
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Accept your invitation</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">
        Set a password to join
        <span class="font-medium" style="color:var(--foreground)">{{ $organizationName ?? 'the organization' }}</span>
        as <span class="font-medium" style="color:var(--foreground)">{{ $email }}</span>.
    </p>

    <form wire:submit="accept" class="mt-7 space-y-4">
        <input type="hidden" name="email" value="{{ $email }}" autocomplete="username">
        <div x-data="{ pw: '' }">
            <label class="label" for="password">Choose a password</label>
            <input wire:model="password" x-on:input="pw = $event.target.value"
                   id="password" name="password" type="password"
                   autocomplete="new-password" minlength="12"
                   class="input input-lg" placeholder="At least 12 characters" autofocus
                   aria-describedby="password-policy @error('password') password-error @enderror"
                   @error('password') aria-invalid="true" @enderror>
            <div id="password-policy" class="mt-2 flex items-center gap-1.5 text-xs" style="color:var(--faint)">
                <x-icon name="check" class="w-3.5 h-3.5" x-bind:style="pw.length >= 12 ? 'color:var(--success-strong)' : ''" />
                <span x-bind:style="pw.length >= 12 ? 'color:var(--success-strong)' : ''">At least 12 characters</span>
                <span class="mx-1" aria-hidden="true">·</span>
                <span>checked against known breaches</span>
            </div>
            @error('password') <p class="field-error" id="password-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled" wire:target="accept">
            <span wire:loading.remove wire:target="accept">Accept &amp; sign in</span>
            <span wire:loading wire:target="accept" class="inline-flex items-center gap-2"><span class="spinner"></span> Setting up…</span>
        </button>
    </form>
</div>
