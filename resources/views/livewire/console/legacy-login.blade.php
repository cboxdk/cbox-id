<?php

use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\Migration\LegacyLoginApprovals;
use App\Platform\Migration\LegacyLoginProbe;
use App\Platform\Sudo;
use Illuminate\Auth\Access\AuthorizationException;
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
        app(ConsoleScope::class)->assertMayAdministerEnvironment();
    }

    /** The address to probe with — the operator's own, never somebody else's. */
    public string $probeEmail = '';

    public ?string $probeResult = null;

    /**
     * Ask the declared endpoint whether it is alive, before anybody approves it.
     *
     * Without this an operator approves blind, and the first thing to discover that the
     * endpoint does not answer is a real person's login — failing closed, so they simply
     * cannot sign in and nobody knows why.
     */
    public function probe(LegacyLoginApprovals $approvals, LegacyLoginProbe $probe): void
    {
        app(ConsoleScope::class)->assertMayAdministerEnvironment();

        $declaration = $approvals->current();

        if ($declaration === null || ! filter_var($this->probeEmail, FILTER_VALIDATE_EMAIL)) {
            $this->probeResult = 'Enter an address that exists in your old system.';

            return;
        }

        $this->probeResult = $probe->describe($declaration, $this->probeEmail);
    }

    public function approve(LegacyLoginApprovals $approvals): void
    {
        app(ConsoleScope::class)->assertMayAdministerEnvironment();

        // ASKED AGAIN HERE, not only on the route. Route middleware runs on the first page
        // load; every Livewire action after it is a POST to the component endpoint, so a
        // window opened before the sudo confirmation expired can still call this an hour
        // later. This is the one click in the console that redirects where passwords go.
        $this->assertFreshStepUp();

        // `id()` is non-null behind the console's own gate — `assertMayAdminister()` has
        // already refused anybody without a session — so there is nothing to branch on.
        $approvals->approve(app(CurrentUser::class)->id());

        $this->dispatch('toast', message: 'Approved. Sign-ins for people who are not in Cbox ID yet now go to that endpoint.');
    }

    public function revoke(LegacyLoginApprovals $approvals): void
    {
        app(ConsoleScope::class)->assertMayAdministerEnvironment();

        // Withdrawing is the safe direction, and it is still gated: an attacker who can
        // silently turn the migration OFF locks every un-migrated person out of the
        // product, which is an outage they chose the timing of.
        $this->assertFreshStepUp();

        $approvals->revoke();

        $this->dispatch('toast', message: 'Withdrawn. People already migrated are unaffected; anyone still on the old system cannot sign in.');
    }

    /**
     * Refuse an action from a session that has not re-confirmed recently.
     *
     * @throws AuthorizationException
     */
    private function assertFreshStepUp(): void
    {
        if (! app(Sudo::class)->confirmed()) {
            throw new AuthorizationException('Confirm it is you before changing where sign-ins are sent.');
        }
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
                {{-- Two bare `.badge`s rendered as the same grey pill, so the one state on
                     this page that matters — is it live — was carried by the word alone. --}}
                @if ($declaration->isApproved())
                    <span class="badge badge-success">Active</span>
                @else
                    <span class="badge badge-warn">Awaiting approval</span>
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

            {{-- The check before the decision, and above it on purpose: an operator who has
                 not tried the endpoint should meet this first. It sends no password —
                 `find()` asks whether the address is known and nothing more, so this screen
                 cannot become a credential-testing oracle with an approve button beside
                 it. --}}
            <div class="cbx-panel-body" style="padding-top:0;display:flex;flex-direction:column;gap:8px">
                <label class="label" for="ll-probe-email">Try it first</label>
                <div class="flex flex-wrap items-center gap-2">
                    {{-- A real label, not a placeholder: the placeholder disappears on the
                         first keystroke, and this field is the one somebody looks away from
                         mid-form to go and find an address. --}}
                    <input id="ll-probe-email" wire:model="probeEmail" type="email" class="input" style="max-width:20rem"
                           placeholder="an address in your old system"
                           aria-invalid="@error('probeEmail') true @else false @enderror"
                           aria-describedby="ll-probe-result">
                    <button wire:click="probe" class="btn btn-ghost" wire:loading.attr="disabled" wire:target="probe">
                        <span class="spinner" wire:loading wire:target="probe" aria-hidden="true"></span> Test endpoint
                    </button>
                </div>
                @error('probeEmail') <p class="field-error">{{ $message }}</p> @enderror
                @if ($probeResult !== null)
                    <p id="ll-probe-result" class="text-xs" role="status" aria-live="polite" style="color:var(--muted)">{{ $probeResult }}</p>
                @endif
            </div>

            <div class="cbx-panel-body" style="padding-top:0">
                {{-- TYPE-TO-CONFIRM, per the rule written into the component itself: this
                     is not undoable from the console in any meaningful sense — once an
                     endpoint has been approved it has been offered live passwords — and the
                     dialog names the environment, which is the failure being designed
                     against here more than anywhere: staging and production in two
                     identical tabs. A native dialog is dismissed by Enter.

                     The typed string is the endpoint's HOST, not the app's name: the host
                     is the fact being agreed to, and typing it is reading it. --}}
                @if ($declaration->isApproved())
                    <x-confirm-delete
                        :name="parse_url($declaration->url, PHP_URL_HOST) ?: $declaration->url"
                        action="revoke"
                        label="Withdraw"
                        verb="Withdraw"
                        consequence="Anyone who has not migrated yet stops being able to sign in until this is approved again. People already moved are unaffected."
                        trigger-class="btn btn-ghost"
                    />
                @else
                    <x-confirm-delete
                        :name="parse_url($declaration->url, PHP_URL_HOST) ?: $declaration->url"
                        action="approve"
                        label="Approve this endpoint"
                        verb="Approve"
                        consequence="From now on, the email and password of anyone who is not in Cbox ID yet are sent to that endpoint."
                        trigger-class="btn btn-danger"
                    />
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
