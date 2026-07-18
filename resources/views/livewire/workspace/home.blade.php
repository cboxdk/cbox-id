<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Contracts\Accounts;
use Cbox\Id\Platform\Exceptions\EnvironmentLimitReached;
use Cbox\Id\Platform\Models\Account;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Workspace › Environments — the account's environment launchpad. Lists the
 * environments the account owns (each linking out to its own host-resolved domain,
 * where it is administered) and lets the member stand up more, up to the plan's
 * allowance. Stateless: no "current environment" is pinned — environments resolve
 * by host.
 */
new #[Layout('components.layouts.workspace', ['title' => 'Environments'])] class extends Component
{
    public string $newEnvironment = '';

    public string $newEnvironmentType = 'production';

    /** Add a new environment under the account, respecting role and plan allowance. */
    public function addEnvironment(AccountAuth $auth, AccountProvisioner $provisioner): void
    {
        $member = $auth->current();
        $account = $member?->account;

        // Only roles that manage environments may create them.
        if ($account === null || ! $member->role->canManageEnvironments()) {
            return;
        }

        $this->validate([
            'newEnvironment' => 'required|string|max:120',
            'newEnvironmentType' => ['required', Rule::enum(EnvironmentType::class)],
        ]);

        try {
            $provisioner->addEnvironment($account, trim($this->newEnvironment), type: EnvironmentType::from($this->newEnvironmentType));
        } catch (EnvironmentLimitReached) {
            $this->addError('newEnvironment', 'Your plan is at its environment limit. Upgrade to add more.');

            return;
        }

        $this->newEnvironment = '';
        $this->newEnvironmentType = 'production';
        session()->flash('status', 'Environment created.');
    }

    private function account(AccountAuth $auth): ?Account
    {
        return $auth->current()?->account;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(AccountAuth $auth, Accounts $accounts, AccountMembers $members): array
    {
        $member = $auth->current();
        $account = $member?->account;

        // Only the environments this member may reach — an all-access member sees
        // every one the account owns; a scoped member sees only their grants.
        $accessibleIds = $member === null ? [] : $members->accessibleEnvironmentIds($member);

        /** @var Collection<int, Environment> $environments */
        $environments = $account === null
            ? collect()
            : Environment::query()->whereIn('id', $accessibleIds)->orderBy('created_at')->get();

        $base = config('cbox-id.environments.base_domains', []);
        $baseDomain = is_array($base) && $base !== [] ? (string) $base[0] : request()->getHost();

        return [
            'member' => $member,
            'account' => $account,
            'environments' => $environments,
            'canManage' => $member?->role->canManageEnvironments() ?? false,
            'scoped' => $member !== null && ! $member->all_environments,
            'remaining' => $account !== null ? $accounts->remainingEnvironments($account) : 0,
            'baseDomain' => $baseDomain,
        ];
    }
}; ?>

<div>
    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Environments</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">Each environment is an isolated IdP with its own users, keys, and sign-in.</p>
        </div>
        @unless ($scoped)
            <span class="text-xs shrink-0" style="color:var(--faint)">{{ $environments->count() }} of {{ $account?->environment_limit ?? 0 }} used</span>
        @endunless
    </div>
    @if ($scoped)
        <p class="mt-2 text-xs" style="color:var(--faint)">You have access to {{ $environments->count() }} {{ \Illuminate\Support\Str::plural('environment', $environments->count()) }} in this account.</p>
    @endif

    <div class="mt-6 space-y-3">
        @forelse ($environments as $environment)
            @php
                // Environments resolve statelessly by host: a custom domain if set,
                // else the {slug}.{base_domain} subdomain. This is the address you
                // administer the environment at — the workspace root just links out.
                $url = $environment->domain !== null
                    ? 'https://'.$environment->domain
                    : 'https://'.$environment->slug.'.'.$baseDomain;
            @endphp
            <div class="rounded-xl border p-4 flex items-center justify-between gap-4" style="border-color:var(--border)">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-medium truncate">{{ $environment->name }}</span>
                        @if ($environment->isSandbox())
                            <span class="text-xs rounded-full px-2 py-0.5 font-medium" style="background:color-mix(in oklch,var(--warning) 15%,transparent);color:var(--warning)">Sandbox</span>
                        @endif
                        <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $environment->status }}</span>
                    </div>
                    <a href="{{ $url }}" target="_blank" rel="noopener" class="mt-1 block text-sm truncate underline underline-offset-2" style="color:var(--accent)">{{ $url }}</a>
                </div>
                <a href="{{ $url }}" target="_blank" rel="noopener" class="btn btn-primary btn-sm shrink-0">Open ↗</a>
            </div>
        @empty
            <p class="text-sm" style="color:var(--muted)">No environments yet.</p>
        @endforelse
    </div>

    {{-- Creating environments is a management action on the whole account. --}}
    @if ($canManage && ! $scoped)
        <div class="mt-8">
            <h2 class="font-medium">Add an environment</h2>
            <p class="mt-1 text-sm" style="color:var(--muted)">
                @if ($remaining > 0)
                    Stand up another isolated environment — e.g. staging alongside production. {{ $remaining }} remaining on your plan.
                @else
                    You've used every environment your plan allows. Upgrade to add more.
                @endif
            </p>
            <form wire:submit="addEnvironment" class="mt-3 flex items-start gap-2">
                <div class="flex-1">
                    <input wire:model="newEnvironment" type="text" class="input" placeholder="Staging" @disabled($remaining <= 0)>
                    @error('newEnvironment') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <select wire:model="newEnvironmentType" class="input" style="width:auto" aria-label="Environment type" @disabled($remaining <= 0)>
                    <option value="production">Production</option>
                    <option value="sandbox">Sandbox</option>
                </select>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="addEnvironment" @disabled($remaining <= 0)>Create</button>
            </form>
            <p class="mt-2 text-xs" style="color:var(--faint)">Sandbox environments allow localhost URLs and never send real email — for development and testing.</p>
        </div>
    @endif
</div>
