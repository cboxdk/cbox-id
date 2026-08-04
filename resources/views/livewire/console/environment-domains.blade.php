<?php

declare(strict_types=1);

use App\Platform\AccountActivity;
use App\Platform\AccountAuth;
use App\Platform\AccountCapabilities;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\Organization\Contracts\EnvironmentDomains;
use Cbox\Id\Organization\Exceptions\InvalidCustomDomain;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Identity platform › Environment domains — attach a custom domain (id.acme.com) to an
 * environment so its issuer publishes on that host instead of the default
 * {slug}.{base_domain}. Self-serve, DNS-TXT verified via the framework's
 * EnvironmentDomains contract. Only a member who can manage environments, and only
 * for an environment they can reach. TLS for the domain is an operator/ingress
 * concern once verified (the page says so) — the app only proves control.
 */
new #[Layout('components.layouts.app', ['title' => 'Environment domains'])] class extends Component
{
    public string $selectedEnvironment = '';

    public string $newDomain = '';

    public ?string $verifyError = null;

    public function mount(AccountAuth $auth, AccountMembers $members, ConsoleScope $scope): void
    {
        $member = $auth->current();

        // Two questions, and both have to be asked — see environment-keys.blade.php: the
        // scope decides admission, the member row is what the page reads.
        if ($member === null || $scope->capabilities()?->canManageEnvironments() !== true) {
            $this->redirect(route('projects'));

            return;
        }

        $ids = $members->accessibleEnvironmentIds($member);
        $first = Environment::query()->whereIn('id', $ids)->orderBy('created_at')->value('id');
        $this->selectedEnvironment = is_string($first) ? $first : '';
    }

    public function request(AccountAuth $auth, AccountMembers $members, EnvironmentDomains $domains): void
    {
        if (! $this->guard($auth, $members)) {
            return;
        }

        $this->validate(['newDomain' => ['required', 'string', 'max:253']]);

        try {
            $domains->request($this->selectedEnvironment, trim($this->newDomain));
        } catch (InvalidCustomDomain $e) {
            $this->addError('newDomain', $e->getMessage());

            return;
        }

        $this->reset('newDomain', 'verifyError');
    }

    public function verify(AccountAuth $auth, AccountMembers $members, EnvironmentDomains $domains, AccountActivity $activity): void
    {
        if (! $this->guard($auth, $members)) {
            return;
        }

        $result = $domains->verify($this->selectedEnvironment);

        if (! $result->verified) {
            $this->verifyError = 'The DNS TXT record isn\'t visible yet. DNS can take a few minutes to propagate — try again shortly.';

            return;
        }

        $this->verifyError = null;

        $member = $auth->current();
        if ($member !== null) {
            $activity->record($member->account_id, 'account.custom_domain_verified', $member->id,
                targetType: 'environment', targetId: $this->selectedEnvironment,
                context: ['domain' => $result->domain], request: request());
        }

        $this->dispatch('toast', message: $result->domain.' is verified and now serves this environment.');
    }

    public function remove(AccountAuth $auth, AccountMembers $members, EnvironmentDomains $domains, AccountActivity $activity): void
    {
        if (! $this->guard($auth, $members)) {
            return;
        }

        $domains->clear($this->selectedEnvironment);
        $this->reset('verifyError');

        $member = $auth->current();
        if ($member !== null) {
            $activity->record($member->account_id, 'account.custom_domain_removed', $member->id,
                targetType: 'environment', targetId: $this->selectedEnvironment, request: request());
        }

        $this->dispatch('toast', message: 'Custom domain removed.');
    }

    /** The member manages environments AND the selected one is theirs to reach. */
    private function guard(AccountAuth $auth, AccountMembers $members): bool
    {
        $member = $auth->current();

        if ($member === null || ! AccountCapabilities::of($member->role)->canManageEnvironments() || $this->selectedEnvironment === '') {
            return false;
        }

        return in_array($this->selectedEnvironment, $members->accessibleEnvironmentIds($member), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(AccountAuth $auth, AccountMembers $members, EnvironmentDomains $domains): array
    {
        $member = $auth->current();
        $ids = $member !== null ? $members->accessibleEnvironmentIds($member) : [];

        $environment = $this->selectedEnvironment !== ''
            ? Environment::query()->whereIn('id', $ids)->find($this->selectedEnvironment)
            : null;

        return [
            'environments' => Environment::query()->whereIn('id', $ids)->orderBy('created_at')->get(),
            'verifiedDomain' => $environment?->domain,

            // Read through the SAME resolution as everything else on this page.
            //
            // `selectedEnvironment` is a live-bound, unlocked property, and every write
            // path funnels through guard() — but this read did not: it passed the raw
            // value to a service that resolves it with a bare `Environment::find()`, and
            // `Environment` is the tenancy root with no scope of its own. So a member
            // could name an environment belonging to a different account and read back
            // its unannounced domain and the `cbox-id-domain-verification=…` TXT proof.
            // A bogus id 500'd rather than 404'd, which is its own tell.
            'challenge' => $environment !== null ? $domains->challenge($environment->id) : null,
        ];
    }
}; ?>

<div class="max-w-2xl">
    {{-- No margin override: the component owns the rhythm, and merging `mb-8` onto its
         `mb-6` leaves both classes on the element with the stylesheet order deciding. --}}
    <x-page-header title="Environment domains"
                   subtitle="Serve an environment's identity endpoints on your own domain, verified by DNS." />

    @if ($environments->isEmpty())
        <div class="cbx-empty"><div class="cbx-empty-icon"><x-icon name="layers" class="w-5 h-5" /></div><h3>No environments yet</h3><p>Create an environment first, then you can give it a custom domain.</p></div>
    @else

        <div class="card p-5 space-y-5">
            <div>
                <label for="env" class="label">Environment</label>
                <select wire:model.live="selectedEnvironment" id="env" class="input">
                    @foreach ($environments as $env)
                        <option value="{{ $env->id }}">{{ $env->name }} ({{ $env->slug }})</option>
                    @endforeach
                </select>
            </div>

            @if ($verifiedDomain)
                {{-- A domain is live for this environment. --}}
                <div class="rounded-xl border p-4" style="border-color:color-mix(in oklch,var(--success) 35%,transparent);background:var(--success-soft)">
                    <div class="flex items-center gap-2">
                        <x-icon name="shield" class="w-4 h-4" style="color:var(--success-strong)" />
                        <span class="font-medium">{{ $verifiedDomain }}</span>
                        <span class="badge">Verified</span>
                    </div>
                    <p class="mt-2 text-sm" style="color:var(--muted)">This environment's issuer, discovery and JWKS are served on <span class="mono">https://{{ $verifiedDomain }}</span>. Point the host at your ingress and terminate TLS there.</p>
                </div>
                <x-confirm-delete
                    :name="$verifiedDomain"
                    action="remove"
                    label="Remove domain"
                    verb="Stop serving"
                    trigger-class="btn btn-ghost"
                    trigger-style="color:var(--destructive)"
                    consequence="This environment falls back to its default domain. Every client pinned to the current issuer, discovery URL or JWKS URL breaks until it is repointed." />
            @elseif ($challenge)
                {{-- Pending verification: show the exact TXT record to publish. --}}
                <div>
                    <p class="text-sm" style="color:var(--muted)">Add this DNS <strong>TXT</strong> record at your domain, then verify. It proves you control <span class="mono">{{ $challenge->domain }}</span>.</p>
                    <dl class="mt-3 rounded-xl border divide-y" style="border-color:var(--border)">
                        <div class="flex items-center justify-between gap-4 p-3">
                            <dt class="text-xs uppercase tracking-wide" style="color:var(--faint)">Type</dt>
                            <dd class="mono text-sm">TXT</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 p-3">
                            <dt class="text-xs uppercase tracking-wide" style="color:var(--faint)">Name</dt>
                            <dd class="mono text-sm break-all text-right">{{ $challenge->recordName }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 p-3">
                            <dt class="text-xs uppercase tracking-wide" style="color:var(--faint)">Value</dt>
                            <dd class="mono text-sm break-all text-right">{{ $challenge->recordValue }}</dd>
                        </div>
                    </dl>
                    @if ($verifyError)
                        <p class="mt-3 text-sm" style="color:var(--destructive)">{{ $verifyError }}</p>
                    @endif
                    <div class="mt-4 flex items-center gap-2">
                        <button wire:click="verify" wire:loading.attr="disabled" wire:target="verify" class="btn btn-primary">Verify</button>
                        {{-- Discards the pending claim, including the DNS TXT challenge the
                             admin may already have published. Reversible, but only by
                             re-adding the domain and publishing a NEW record. --}}
                        <button wire:click="remove" class="btn btn-ghost"
                                wire:loading.attr="disabled" wire:target="remove"
                                wire:confirm="Cancel verification for {{ $challenge->domain ?? 'this domain' }}?&#10;&#10;The DNS TXT challenge below stops being valid — re-adding the domain issues a new one.">Cancel</button>
                    </div>
                </div>
            @else
                {{-- No domain yet: request one. --}}
                <form wire:submit="request" class="space-y-3">
                    <div>
                        <label for="domain" class="label">Custom domain</label>
                        <input wire:model="newDomain" id="domain" type="text" class="input" placeholder="id.yourcompany.com" autocomplete="off">
                        @error('newDomain')<p class="mt-1 text-sm" style="color:var(--destructive)">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="request">Add domain</button>
                </form>
            @endif
        </div>

        <p class="flex items-center gap-1.5 text-xs mt-4" style="color:var(--faint)"><x-icon name="shield" class="w-3.5 h-3.5 shrink-0" /> Cbox ID verifies domain control; issuing the TLS certificate is your ingress's job (cert-manager, on-demand TLS, …).</p>
    @endif
</div>
