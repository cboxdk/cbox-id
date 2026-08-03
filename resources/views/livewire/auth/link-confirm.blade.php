<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * "Do you want to connect this account?" — the last step of a social sign-in whose
 * address already belonged to someone here.
 *
 * This screen exists because the alternatives are both wrong. Merging the two accounts
 * because the addresses match trusts the provider's word for an address, which we have
 * decided we cannot: Discord will carry whatever someone typed. Refusing outright leaves
 * the legitimate owner — who really does own both — with no way through.
 *
 * So we ask. By the time this renders, three separate things are true: the person
 * completed the provider's sign-in, they authenticated here, and they are about to say
 * so out loud. That is strictly more than address equality ever proved, and it does not
 * care that a GitHub account may carry five addresses.
 *
 * The hold in {@see \App\Http\Middleware\Authenticate} keeps every authenticated request
 * on this page until one of the two buttons is pressed, so a link never sits half-made.
 */
new #[Layout('components.layouts.auth', ['title' => 'Connect your account'])] class extends Component
{
    public string $provider = '';

    public ?string $email = null;

    public ?string $name = null;

    public function mount(PlatformAuth $auth, CurrentUser $me): void
    {
        $pending = $auth->pendingLink($me->id());

        // Nothing waiting — someone typed the URL, or answered in another tab. Not an
        // error worth a page: send them where they were going.
        if ($pending === null) {
            $this->redirectRoute('dashboard', navigate: true);

            return;
        }

        $this->provider = $pending->label();
        $this->email = $pending->email;
        $this->name = $pending->name;
    }

    public function connect(PlatformAuth $auth, CurrentUser $me): void
    {
        // Re-read rather than trusting the mounted state. The public properties above
        // are display copies that ride in the wire snapshot and come back with every
        // update; the decision has to be made against the session, which the browser
        // cannot reach. Without this, editing `provider` in the payload would be enough
        // to describe one link and create another.
        $linked = $auth->confirmPendingLink($me->id());

        session()->flash(
            $linked ? 'status' : 'error',
            $linked
                ? $this->provider.' is now connected to your account. You can sign in with it from now on.'
                : 'That connection could not be completed. It may have expired or already be connected to another account.',
        );

        $this->redirectRoute('account', navigate: true);
    }

    public function decline(PlatformAuth $auth): void
    {
        $auth->discardPendingLink();

        // Worth saying plainly: someone seeing this screen who did NOT just sign in with
        // that provider is looking at evidence that another person tried to, using their
        // address. Declining is the right answer and the message says what it means.
        session()->flash('status', 'Not connected. Nothing was changed on your account.');

        $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold tracking-tight" style="color:var(--fg)">Connect {{ $provider }}?</h1>

    <p class="mt-2 text-sm" style="color:var(--fg-muted)">
        Someone just signed in with {{ $provider }}
        @if ($email)
            as <span class="font-medium" style="color:var(--fg)">{{ $email }}</span>
        @endif
        — an address that already belongs to your account.
    </p>

    <div class="mt-5 rounded-lg p-4 text-sm" style="background:var(--surface-2);border:1px solid var(--border)">
        <p style="color:var(--fg)">
            <b>If that was you</b>, connect it and you'll be able to sign in with
            {{ $provider }} or with your password from now on.
        </p>
        <p class="mt-2.5" style="color:var(--fg-muted)">
            <b>If it wasn't</b>, decline. Someone else tried to sign in using your email
            address. Nothing will be added to your account, and your password still works
            as before.
        </p>
    </div>

    <div class="mt-6 flex flex-col-reverse gap-2.5 sm:flex-row">
        <button type="button" wire:click="decline" class="btn btn-ghost sm:flex-1">
            No, that wasn't me
        </button>
        <button type="button" wire:click="connect" class="btn btn-primary sm:flex-1" wire:loading.attr="disabled">
            Yes, connect {{ $provider }}
        </button>
    </div>

    <p class="mt-5 text-xs" style="color:var(--fg-subtle)">
        You can disconnect {{ $provider }} at any time from your account's security
        settings.
    </p>
</div>
