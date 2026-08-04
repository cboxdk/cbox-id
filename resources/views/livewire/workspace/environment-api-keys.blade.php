<?php

declare(strict_types=1);

use App\Platform\AccountActivity;
use App\Platform\AccountAuth;
use App\Platform\WorkspaceSudo;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Workspace › Environment keys — issue and revoke ENVIRONMENT management-plane keys
 * (`cbid_env_…`), the machine credential apps use to provision organizations and
 * users inside one environment. Distinct from account keys: an environment key is
 * bound to a single environment and carries fine-grained scopes, not a role.
 *
 * High-privilege (a key can provision identities), so only roles that manage
 * environments may mint or revoke them, and only for an environment they can reach.
 * The plaintext is shown exactly once.
 */
new #[Layout('components.layouts.workspace', ['title' => 'Environment keys'])] class extends Component
{
    public string $selectedEnvironment = '';

    public string $newKeyName = '';

    /**
     * Livewire rehydrates this straight off the wire, so the keys are whatever the
     * request sent — not necessarily a gapless list. Hence the array_values() before
     * it is handed on.
     *
     * @var array<array-key, string>
     */
    public array $newKeyScopes = [];

    /** The just-created plaintext, shown once and never persisted. */
    /**
     * The plaintext key, held only for the single render that reveals it.
     *
     * PROTECTED, not public. Livewire serialises public properties into the wire snapshot
     * embedded in the DOM and echoes them back in the body of every subsequent
     * /livewire/update request until they are reset — so a full-authority credential sat
     * in the page for any XSS or browser extension to read, and was re-transmitted on
     * every round trip into request-body logs and APM traces. The rest of this codebase
     * gets this right; these were the outliers.
     */
    protected ?string $freshKey = null;

    public function mount(AccountAuth $auth, AccountMembers $members): void
    {
        $member = $auth->current();

        if ($member === null || ! $member->role->canManageEnvironments()) {
            $this->redirect(route('workspace.home'));

            return;
        }

        // Default to read-only scopes — an admin opts into write explicitly.
        $this->newKeyScopes = [
            EnvironmentApiScope::OrganizationsRead->value,
            EnvironmentApiScope::UsersRead->value,
        ];

        $ids = $members->accessibleEnvironmentIds($member);
        $first = Environment::query()->whereIn('id', $ids)->orderBy('created_at')->value('id');
        $this->selectedEnvironment = is_string($first) ? $first : '';
    }

    public function createKey(AccountAuth $auth, AccountMembers $members, EnvironmentApiKeys $keys, AccountActivity $activity): void
    {
        if ($this->requiresSudo('workspace.environment-keys')) {
            return;
        }

        if (! $this->guard($auth, $members)) {
            return;
        }

        $this->validate([
            'newKeyName' => ['required', 'string', 'max:120'],
            'newKeyScopes' => ['required', 'array', 'min:1'],
            'newKeyScopes.*' => ['in:'.implode(',', EnvironmentApiScope::all())],
        ]);

        $issued = $keys->issue($this->selectedEnvironment, trim($this->newKeyName), array_values($this->newKeyScopes));

        $member = $auth->current();
        if ($member !== null) {
            $activity->record($member->account_id, 'account.environment_key_created', $member->id,
                targetType: 'environment', targetId: $this->selectedEnvironment,
                context: ['name' => trim($this->newKeyName), 'scopes' => array_values($this->newKeyScopes)],
                request: request());
        }

        $this->freshKey = $issued->plaintext;
        $this->reset('newKeyName');
    }

    public function revokeKey(string $id, AccountAuth $auth, AccountMembers $members, EnvironmentApiKeys $keys, AccountActivity $activity): void
    {
        // Revoking is as consequential as issuing, and was not gated. A stolen but
        // non-sudo session could not MINT persistence — create requires the step-up — but
        // it could destroy the machine credentials that run provisioning and automation,
        // which is a denial of service the same session was otherwise held back from.
        if ($this->requiresSudo('workspace.environment-keys')) {
            return;
        }

        if (! $this->guard($auth, $members)) {
            return;
        }

        // Only revoke a key that belongs to the selected (and accessible) environment.
        if ($keys->forEnvironment($this->selectedEnvironment)->firstWhere('id', $id) !== null) {
            $keys->revoke($this->selectedEnvironment, $id);

            $member = $auth->current();
            if ($member !== null) {
                $activity->record($member->account_id, 'account.environment_key_revoked', $member->id,
                    targetType: 'environment', targetId: $this->selectedEnvironment,
                    context: ['key_id' => $id], request: request());
            }

            $this->dispatch('toast', message: 'Environment key revoked.');
        }
    }

    /** The member manages environments AND the selected one is theirs to reach. */
    private function guard(AccountAuth $auth, AccountMembers $members): bool
    {
        $member = $auth->current();

        if ($member === null || ! $member->role->canManageEnvironments() || $this->selectedEnvironment === '') {
            return false;
        }

        return in_array($this->selectedEnvironment, $members->accessibleEnvironmentIds($member), true);
    }

    private function requiresSudo(string $returnRoute): bool
    {
        if (app(WorkspaceSudo::class)->confirmed()) {
            return false;
        }

        session()->put('workspace.sudo.intended', route($returnRoute));
        $this->redirectRoute('workspace.sudo', navigate: false);

        return true;
    }

    /** @return array<string, mixed> */
    public function with(AccountAuth $auth, AccountMembers $members, EnvironmentApiKeys $keys): array
    {
        $member = $auth->current();
        $ids = $member === null ? [] : $members->accessibleEnvironmentIds($member);

        /** @var Collection<int, Environment> $environments */
        $environments = Environment::query()->whereIn('id', $ids)->orderBy('created_at')->get();

        $valid = $this->selectedEnvironment !== '' && in_array($this->selectedEnvironment, $ids, true);

        return ['freshKey' => $this->freshKey, 
            'environments' => $environments,
            'keys' => $valid ? $keys->forEnvironment($this->selectedEnvironment) : collect(),
            'scopes' => EnvironmentApiScope::cases(),
        ];
    }
}; ?>

<div>
    {{-- See api-keys.blade.php: these two were the workspace plane's only pages with a
         hand-rolled h1 and no eyebrow. --}}
    <x-page-header title="Environment keys"
                   subtitle="Machine credentials for the per-environment management API — provision organizations and users inside one environment. Each key carries explicit scopes.">
        <x-slot:actions>
            <a href="/api/v1/environment/openapi.yaml" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">API reference ↗</a>
        </x-slot:actions>
    </x-page-header>

    @if ($environments->isEmpty())
        <div class="cbx-empty mt-6"><div class="cbx-empty-icon"><x-icon name="layers" class="w-5 h-5" /></div><h3>No environments yet</h3><p>Create an environment first, then you can issue keys scoped to it.</p></div>
    @else
        <div class="mt-6">
            <label for="env-select" class="text-sm font-medium">Environment</label>
            <select id="env-select" wire:model.live="selectedEnvironment" class="input mt-1" style="max-width:24rem">
                @foreach ($environments as $environment)
                    <option value="{{ $environment->id }}">{{ $environment->name }}{{ $environment->isSandbox() ? ' (sandbox)' : '' }}</option>
                @endforeach
            </select>
        </div>

        {{-- The plaintext, shown exactly once. --}}
        @if ($freshKey !== null)
            <div class="mt-6 rounded-xl border p-4" style="border-color:color-mix(in oklch,var(--success) 35%,transparent);background:var(--success-soft)">
                <p class="text-sm font-medium" style="color:var(--success-strong)">Copy your key now — you won't be able to see it again.</p>
                <div class="mt-3 flex items-center gap-2">
                    <code class="flex-1 min-w-0 truncate rounded-lg px-3 py-2 text-sm" style="background:var(--background);border:1px solid var(--border)">{{ $freshKey }}</code>
                    <x-copy-button :value="$freshKey" class="btn-primary" />
                </div>
            </div>
        @endif

        <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
            @forelse ($keys as $key)
                <div class="flex items-center gap-3 p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-medium truncate">{{ $key->name }}</span>
                            @foreach ($key->scopes as $scope)
                                <span class="badge mono">{{ $scope }}</span>
                            @endforeach
                            @if (! $key->isActive())
                                <span class="badge badge-danger">revoked</span>
                            @endif
                        </div>
                        <p class="text-sm truncate mono" style="color:var(--muted)">{{ $key->prefix }}…&nbsp; · &nbsp;{{ $key->last_used_at ? 'last used '.$key->last_used_at->diffForHumans() : 'never used' }}</p>
                    </div>
                    @if ($key->isActive())
                        <x-confirm-delete
                            :name="$key->name"
                            action="revokeKey('{{ $key->id }}')"
                            label="Revoke"
                            consequence="Any integration still presenting this key stops working immediately. This cannot be undone." />
                    @endif
                </div>
            @empty
                <div class="cbx-empty"><div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div><h3>No keys yet</h3><p>Create a key to provision organizations and users in this environment.</p></div>
            @endforelse
        </div>

        <div class="mt-6 rounded-xl border p-5" style="border-color:var(--border)">
            <p class="text-sm font-medium">Create an environment key</p>
            <p class="mt-1 text-sm" style="color:var(--muted)">The key can do only what its scopes allow. Read never implies write.</p>
            <form wire:submit="createKey" class="mt-4 space-y-4">
                <div>
                    <input wire:model="newKeyName" type="text" class="input" placeholder="Provisioning service" aria-label="Key name" style="max-width:24rem">
                    @error('newKeyName') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="grid sm:grid-cols-2 gap-2">
                    @foreach ($scopes as $scope)
                        <label wire:key="scope-{{ $scope }}" class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="newKeyScopes" value="{{ $scope->value }}">
                            <span>{{ $scope->label() }} <code class="mono text-xs" style="color:var(--faint)">{{ $scope->value }}</code></span>
                        </label>
                    @endforeach
                </div>
                @error('newKeyScopes') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="createKey">Create key</button>
            </form>
        </div>
    @endif
</div>
