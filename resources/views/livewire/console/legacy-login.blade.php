<?php

use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\Migration\LegacyLoginApprovals;
use Cbox\Id\OAuthServer\Models\Client;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Approving the legacy login an app has declared.
 *
 * THIS PAGE IS THE HUMAN IN THE LOOP, and it exists because of one asymmetry: everything
 * else an app declares in its manifest affects only that app, while this names a URL that
 * every unknown email — and the password typed with it — will be offered to, on this
 * environment's whole sign-in path. A client holding `apps.manifest` that could switch
 * that on by itself would be a credential harvester with a scope for the purpose.
 *
 * So the page's job is not to make approval convenient. It is to make sure the person
 * clicking knows exactly what they are agreeing to: which app asked, what URL, and what
 * happens to people who are already migrated. All three are on screen before the button.
 */
new #[Layout('components.layouts.console', ['title' => 'Legacy login'])] class extends Component
{
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    public function approve(LegacyLoginApprovals $approvals): void
    {
        app(ConsoleScope::class)->assertMayAdminister();

        // `id()` is non-null behind the console's own gate — `assertMayAdminister()` has
        // already refused anybody without a session — so there is nothing to branch on.
        $approvals->approve(app(CurrentUser::class)->id());

        $this->dispatch('toast', message: 'Approved. Sign-ins for people who are not in Cbox ID yet now go to that endpoint.');
    }

    public function revoke(LegacyLoginApprovals $approvals): void
    {
        app(ConsoleScope::class)->assertMayAdminister();

        $approvals->revoke();

        $this->dispatch('toast', message: 'Withdrawn. People already migrated are unaffected; anyone still on the old system cannot sign in.');
    }

    /** @return array<string, mixed> */
    public function with(LegacyLoginApprovals $approvals): array
    {
        $declaration = $approvals->current();

        return [
            'declaration' => $declaration,
            // Named rather than shown as an id: "this came from Acme Web" is what an
            // operator can judge; a ULID is something they have to take on trust.
            'declaredBy' => $declaration === null
                ? null
                : Client::query()->whereKey($declaration->client_id)->value('name'),
        ];
    }
}; ?>

<div>
    <x-page-header title="Legacy login"
                   subtitle="Where sign-ins go for people who have not moved to Cbox ID yet." />

    @if ($declaration === null)
        <div class="cbx-empty mt-8">
            <div class="cbx-empty-icon"><x-icon name="shield-check" class="w-5 h-5" /></div>
            <h3>No app has declared one</h3>
            <p>
                While you are migrating off another system, your app can declare where its old
                login lives — in its manifest, beside its roles. It appears here for you to
                approve before it does anything.
            </p>
        </div>
    @else
        <div class="cbx-panel mt-6">
            <div class="cbx-panel-header">
                <h2 class="cbx-panel-title">Declared endpoint</h2>
                @if ($declaration->isApproved())
                    <span class="badge">Active</span>
                @else
                    <span class="badge">Awaiting approval</span>
                @endif
            </div>

            <div class="cbx-panel-body" style="display:flex;flex-direction:column;gap:16px">
                <div>
                    <p class="label">Declared by</p>
                    <p class="text-sm">{{ $declaredBy ?? 'an application that no longer exists' }}</p>
                </div>

                <div>
                    <p class="label">Endpoint</p>
                    {{-- Shown in full, and readable. An operator cannot judge a URL they
                         cannot see, and this is the one value on the page they are actually
                         being asked about. --}}
                    <p class="mono text-[13px] break-all">{{ $declaration->url }}</p>
                </div>

                @if ($declaration->isApproved())
                    <div>
                        <p class="label">Approved</p>
                        <p class="text-sm">{{ $declaration->approved_at?->diffForHumans() }}</p>
                    </div>
                @endif

                {{-- The consequence, in the two directions people get wrong. Written here
                     rather than in a tooltip because it is the whole decision. --}}
                <div class="card p-4" style="border-color:var(--accent-edge);background:var(--accent-soft)">
                    <p class="text-sm font-medium">What approving does</p>
                    <ul class="text-xs mt-2 space-y-1" style="color:var(--muted)">
                        <li>Someone who signs in and is <strong>not yet in Cbox ID</strong> has their email and password sent to that endpoint. If it says yes, they are created here and never sent again.</li>
                        <li>Someone who <strong>already exists here</strong> is never sent — their password is the one in Cbox ID, and the old system gets no say in it.</li>
                        <li>If that endpoint is <strong>down</strong>, people who have not migrated cannot sign in. Everyone already moved is unaffected.</li>
                    </ul>
                </div>
            </div>

            <div class="cbx-panel-body" style="padding-top:0">
                @if ($declaration->isApproved())
                    <button wire:click="revoke"
                            wire:confirm="Withdraw this endpoint? Anyone who has not migrated yet will stop being able to sign in."
                            class="btn btn-ghost">Withdraw</button>
                @else
                    <button wire:click="approve"
                            wire:confirm="Approve {{ $declaration->url }}? Passwords typed by people who are not in Cbox ID yet will be sent there."
                            class="btn btn-primary">Approve this endpoint</button>
                @endif
            </div>
        </div>

        <p class="mt-4 text-xs" style="color:var(--faint)">
            If the app declares a different URL later, this approval is dropped and you will be
            asked again — a change to where passwords go is not something to inherit silently.
            Delegated sign-in is a ramp: when few enough people are left on the old system,
            withdraw it and send that group a password reset.
        </p>
    @endif
</div>
