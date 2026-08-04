<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\Console\ConsoleScope;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Signed in, and there is nothing here.
 *
 * WHY THIS PAGE EXISTS. The account door authenticates a PERSON and asks nothing about
 * what they hold — deliberately, and {@see AccountAuth::attempt()} explains why: putting
 * "is this person staff?" back inside the door is what once shut real operators out of
 * the one console they are for. The consequence is that `attempt()` can legitimately
 * answer Ok for somebody with no account membership AND no operator authority: a
 * suspended operator (suspending one of two is supported), or any platform-root subject
 * whose membership was removed.
 *
 * The landing had no answer for them. `workspace.home` and `workspace/security` both
 * `abort_unless($scope->isPlatformOperator(), 403)`, `/platform` 404s by design, and the
 * workspace rail derives from the member's role — so a null role produced ZERO nav areas
 * ({@see \App\Platform\Navigation\ConsoleNavigation::workspace()}). That combination is
 * the worst of the available outcomes: a sign-in that succeeds, a 403 on every page, an
 * empty rail, and — because the only sign-out controls live in the rail and the mobile
 * sheet, both inside a layout the 403 page never renders — no way back out except
 * clearing cookies.
 *
 * WHY NOT REFUSE THE SIGN-IN. It was considered and rejected, for the reason
 * {@see \Tests\Feature\OperatorAuthorityTest} already argues at the door: refusing
 * revokes nothing (the same password still signs them in on any tenant plane they belong
 * to) and re-couples authority to authentication. What was missing was never a refusal —
 * it was a destination.
 *
 * WHAT IT SAYS, AND WHAT IT DOES NOT. One message for all three cases. The page could
 * distinguish a suspended operator from a stranger, and deliberately does not: that would
 * mean confirming to anyone holding any credential that this deployment has a staff
 * console and that they were once on it, which is precisely the disclosure `/platform`
 * answers 404 rather than 403 to avoid. "No workspace on this deployment" is true of all
 * three, and the sign-out is what every one of them actually needs.
 */
new #[Layout('components.layouts.auth', ['title' => 'No workspace yet'])] class extends Component
{
    /**
     * Anyone this plane DOES serve is sent back to it.
     *
     * A dead end reachable by URL from a working session would be its own small version of
     * the bug above, and both guards that redirect here are one membership lookup away
     * from disagreeing with this one if it is not asked the same way.
     */
    public function mount(AccountAuth $auth, ConsoleScope $scope): void
    {
        if ($auth->current() !== null || $scope->isPlatformOperator()) {
            $this->redirectRoute('workspace.home', navigate: false);
        }
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold tracking-tight" style="color:var(--fg)">You're signed in, but there's nothing here yet</h1>

    <p class="mt-2 text-sm" style="color:var(--fg-muted)">
        Your sign-in worked. This address just isn't a member of any workspace on this
        deployment, so there's no console to show you.
    </p>

    <div class="mt-5 rounded-lg p-4 text-sm" style="background:var(--surface-2);border:1px solid var(--border)">
        <p style="color:var(--fg)">
            <b>If you were expecting access</b>, ask whoever invited you to send a new
            invitation to this address. Access is granted per workspace, and an invitation
            is what carries it.
        </p>
        <p class="mt-2.5" style="color:var(--fg-muted)">
            <b>If you have another address</b>, sign out and use that one instead. Nothing
            about this account has changed.
        </p>
    </div>

    <form method="POST" action="{{ route('workspace.logout') }}" class="mt-6">
        @csrf
        <button type="submit" class="btn btn-primary w-full">Sign out</button>
    </form>
</div>
