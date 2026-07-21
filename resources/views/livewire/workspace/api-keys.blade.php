<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\WorkspaceSudo;
use Cbox\Id\Platform\Contracts\AccountApiKeys;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\Models\AccountApiKey;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Workspace › API keys — issue and revoke account management-plane keys (the
 * machine equivalent of a member's session). High-privilege: a key can carry any
 * assignable role, so only member managers (owner/admin) may mint or revoke them.
 * The plaintext is shown exactly once, right after creation.
 */
new #[Layout('components.layouts.workspace', ['title' => 'API keys'])] class extends Component
{
    public string $newKeyName = '';

    public string $newKeyRole = 'developer';

    /** The just-created plaintext, shown once and never persisted. */
    public ?string $freshKey = null;

    public function mount(AccountAuth $auth)
    {
        if (! ($auth->current()?->role->canManageMembers() ?? false)) {
            return redirect()->route('workspace.home');
        }
    }

    public function createKey(AccountAuth $auth, AccountApiKeys $keys): void
    {
        if ($this->requiresSudo('workspace.api-keys')) {
            return;
        }

        $account = $auth->current()?->account;

        if ($account === null || ! ($auth->current()?->role->canManageMembers() ?? false)) {
            return;
        }

        $this->validate([
            'newKeyName' => ['required', 'string', 'max:120'],
            'newKeyRole' => ['required', Rule::in(array_map(fn (AccountRole $r) => $r->value, AccountRole::assignable()))],
        ]);

        $issued = $keys->issue($account->id, trim($this->newKeyName), AccountRole::from($this->newKeyRole));

        $this->freshKey = $issued->plaintext;
        $this->reset('newKeyName');
        $this->newKeyRole = 'developer';
    }

    public function revokeKey(string $id, AccountAuth $auth, AccountApiKeys $keys): void
    {
        $current = $auth->current();

        if ($current === null || ! $current->role->canManageMembers()) {
            return;
        }

        // Only revoke keys that belong to this account.
        $key = $keys->forAccount($current->account_id)->firstWhere('id', $id);

        if ($key !== null) {
            $keys->revoke($id);
            $this->dispatch('toast', message: 'API key revoked.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requiresSudo(string $returnRoute): bool
    {
        if (app(WorkspaceSudo::class)->confirmed()) {
            return false;
        }

        session()->put('workspace.sudo.intended', route($returnRoute));
        $this->redirectRoute('workspace.sudo', navigate: false);

        return true;
    }

    public function with(AccountAuth $auth, AccountApiKeys $keys): array
    {
        $current = $auth->current();

        /** @var Collection<int, AccountApiKey> $list */
        $list = $current === null ? collect() : $keys->forAccount($current->account_id);

        return ['keys' => $list, 'assignableRoles' => AccountRole::assignable()];
    }
}; ?>

<div>
    <div>
        <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">API keys</h1>
        <p class="mt-1 text-sm" style="color:var(--muted)">
            Machine credentials for the account management API — list environments, invite members, read billing. Each key carries a role.
            <a href="/api/v1/openapi.yaml" target="_blank" rel="noopener" class="underline underline-offset-2" style="color:var(--accent)">API reference ↗</a>
        </p>
    </div>

    {{-- The plaintext, shown exactly once. --}}
    @if ($freshKey !== null)
        <div class="mt-6 rounded-xl border p-4" style="border-color:color-mix(in oklch,var(--success) 35%,transparent);background:var(--success-soft)">
            <p class="text-sm font-medium" style="color:var(--success)">Copy your key now — you won't be able to see it again.</p>
            <div class="mt-3 flex items-center gap-2">
                <code class="flex-1 min-w-0 truncate rounded-lg px-3 py-2 text-sm" style="background:var(--background);border:1px solid var(--border)">{{ $freshKey }}</code>
                <button type="button" class="btn btn-primary btn-sm shrink-0" data-copy="{{ $freshKey }}" onclick="navigator.clipboard.writeText(this.getAttribute('data-copy'));var b=this,t=b.textContent;b.textContent='Copied ✓';setTimeout(function(){b.textContent=t},1500)">Copy</button>
            </div>
        </div>
    @endif

    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($keys as $key)
            <div class="flex items-center gap-3 p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="font-medium truncate">{{ $key->name }}</span>
                        <span class="badge">{{ $key->role->label() }}</span>
                        @if (! $key->isActive())
                            <span class="badge badge-danger">revoked</span>
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
            <div class="cbx-empty"><div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div><h3>No API keys yet</h3><p>Create a key to reach the account management API from your own services.</p></div>
        @endforelse
    </div>

    <div class="mt-6 rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Create an API key</p>
        <p class="mt-1 text-sm" style="color:var(--muted)">The key inherits the role you choose and can do only what that role allows.</p>
        <form wire:submit="createKey" class="mt-4 grid sm:grid-cols-[1fr_auto_auto] gap-2 items-start">
            <div>
                <input wire:model="newKeyName" type="text" class="input" placeholder="CI deploy" aria-label="Key name">
                @error('newKeyName') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <select wire:model="newKeyRole" class="input" style="width:auto" aria-label="Key role">
                @foreach ($assignableRoles as $role)
                    <option value="{{ $role->value }}">{{ $role->label() }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary shrink-0" wire:loading.attr="disabled" wire:target="createKey">Create key</button>
        </form>
    </div>
</div>
