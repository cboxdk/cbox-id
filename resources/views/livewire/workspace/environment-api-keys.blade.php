<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
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

    /** @var list<string> */
    public array $newKeyScopes = [];

    /** The just-created plaintext, shown once and never persisted. */
    public ?string $freshKey = null;

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

    public function createKey(AccountAuth $auth, AccountMembers $members, EnvironmentApiKeys $keys): void
    {
        if (! $this->guard($auth, $members)) {
            return;
        }

        $this->validate([
            'newKeyName' => ['required', 'string', 'max:120'],
            'newKeyScopes' => ['required', 'array', 'min:1'],
            'newKeyScopes.*' => ['in:'.implode(',', EnvironmentApiScope::all())],
        ]);

        $issued = $keys->issue($this->selectedEnvironment, trim($this->newKeyName), array_values($this->newKeyScopes));

        $this->freshKey = $issued->plaintext;
        $this->reset('newKeyName');
    }

    public function revokeKey(string $id, AccountAuth $auth, AccountMembers $members, EnvironmentApiKeys $keys): void
    {
        if (! $this->guard($auth, $members)) {
            return;
        }

        // Only revoke a key that belongs to the selected (and accessible) environment.
        if ($keys->forEnvironment($this->selectedEnvironment)->firstWhere('id', $id) !== null) {
            $keys->revoke($this->selectedEnvironment, $id);
            session()->flash('status', 'Environment key revoked.');
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

    /**
     * @return array<string, mixed>
     */
    public function with(AccountAuth $auth, AccountMembers $members, EnvironmentApiKeys $keys): array
    {
        $member = $auth->current();
        $ids = $member === null ? [] : $members->accessibleEnvironmentIds($member);

        /** @var Collection<int, Environment> $environments */
        $environments = Environment::query()->whereIn('id', $ids)->orderBy('created_at')->get();

        $valid = $this->selectedEnvironment !== '' && in_array($this->selectedEnvironment, $ids, true);

        return [
            'environments' => $environments,
            'keys' => $valid ? $keys->forEnvironment($this->selectedEnvironment) : collect(),
            'scopes' => EnvironmentApiScope::cases(),
        ];
    }
}; ?>

<div>
    <div>
        <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Environment keys</h1>
        <p class="mt-1 text-sm" style="color:var(--muted)">
            Machine credentials for the per-environment management API — provision organizations and users inside one environment. Each key carries explicit scopes.
            <a href="/api/v1/environment/openapi.yaml" target="_blank" rel="noopener" class="underline underline-offset-2" style="color:var(--accent)">API reference ↗</a>
        </p>
    </div>

    @if ($environments->isEmpty())
        <p class="mt-6 text-sm" style="color:var(--muted)">You have no environments yet. Create one first.</p>
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
                <p class="text-sm font-medium" style="color:var(--success)">Copy your key now — you won't be able to see it again.</p>
                <div class="mt-3 flex items-center gap-2">
                    <code class="flex-1 min-w-0 truncate rounded-lg px-3 py-2 text-sm" style="background:var(--background);border:1px solid var(--border)">{{ $freshKey }}</code>
                    <button type="button" class="btn btn-primary btn-sm shrink-0" data-copy="{{ $freshKey }}" onclick="navigator.clipboard.writeText(this.getAttribute('data-copy'))">Copy</button>
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
                                <span class="text-xs rounded-full px-2 py-0.5 mono" style="background:var(--surface-2);color:var(--muted)">{{ $scope }}</span>
                            @endforeach
                            @if (! $key->isActive())
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:color-mix(in oklch,var(--destructive) 12%,transparent);color:var(--destructive)">revoked</span>
                            @endif
                        </div>
                        <p class="text-sm truncate mono" style="color:var(--muted)">{{ $key->prefix }}…&nbsp; · &nbsp;{{ $key->last_used_at ? 'last used '.$key->last_used_at->diffForHumans() : 'never used' }}</p>
                    </div>
                    @if ($key->isActive())
                        <button type="button" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)"
                                wire:click="revokeKey('{{ $key->id }}')"
                                wire:confirm="Revoke this key? Any integration using it will stop working immediately.">Revoke</button>
                    @endif
                </div>
            @empty
                <p class="p-4 text-sm" style="color:var(--muted)">No keys for this environment yet.</p>
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
                        <label class="flex items-center gap-2 text-sm">
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
