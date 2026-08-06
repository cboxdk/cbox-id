<?php

declare(strict_types=1);

use App\Platform\OrganizationCapabilities;
use App\Platform\Console\ConsoleScope;
use App\Platform\StepUpReason;
use App\Platform\Sudo;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Identity platform › API keys — issue and revoke organization management-plane keys (the
 * machine equivalent of a member's session). High-privilege: a key can carry any assignable
 * role, so only member managers (owner/admin) may mint or revoke them. The plaintext is
 * shown exactly once, right after creation.
 */
new #[Layout('components.layouts.app', ['title' => 'API keys'])] class extends Component
{
    public string $newKeyName = '';

    public string $newKeyRole = 'developer';

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

    public function mount(ConsoleScope $scope): mixed
    {
        if ($scope->capabilities()?->canManageMembers() !== true) {
            return redirect()->route('projects');
        }

        return null;
    }

    public function createKey(ConsoleScope $scope, OrganizationApiKeys $keys): void
    {
        $organizationId = $scope->organizationId();

        // Authorization FIRST, then the step-up. It ran the other way round, which handed
        // a member who may not mint anything a password prompt and then refused them in
        // silence once they had typed it — and taught everybody else that the prompt is
        // something you get past rather than something that means what it says.
        if ($organizationId === null || $scope->capabilities()?->canManageMembers() !== true) {
            return;
        }

        if ($this->requiresSudo('api-keys', 'An organization API key acts with this role across the whole organization, and its value is shown once.')) {
            return;
        }

        $this->validate([
            'newKeyName' => ['required', 'string', 'max:120'],
            'newKeyRole' => ['required', Rule::in(array_map(fn (MembershipRole $r) => $r->value, MembershipRole::assignable()))],
        ]);

        $issued = $keys->issue($organizationId, trim($this->newKeyName), MembershipRole::from($this->newKeyRole));

        $this->freshKey = $issued->plaintext;
        $this->reset('newKeyName');
        $this->newKeyRole = 'developer';
    }

    public function revokeKey(string $id, ConsoleScope $scope, OrganizationApiKeys $keys): void
    {
        // Revoking is as consequential as issuing, and was not gated. A stolen but
        // non-sudo session could not MINT persistence — create requires the step-up — but
        // it could destroy the machine credentials that run provisioning and automation,
        // which is a denial of service the same session was otherwise held back from.
        $organizationId = $scope->organizationId();

        if ($organizationId === null || $scope->capabilities()?->canManageMembers() !== true) {
            return;
        }

        if ($this->requiresSudo('api-keys', 'Revoking an API key stops whatever is using it, immediately.')) {
            return;
        }

        // Only revoke keys that belong to this organization.
        $key = $keys->forOrganization($organizationId)->firstWhere('id', $id);

        if ($key !== null) {
            $keys->revoke($id);
            $this->dispatch('toast', message: 'API key revoked.');
        }
    }

    /**
     * True when the member has been sent to re-enter their password first.
     *
     * `$reason` is required, not decorative: the step-up screen otherwise says "this is a
     * protected action", which is the sentence people learn to type a password past.
     */
    private function requiresSudo(string $returnRoute, string $reason): bool
    {
        if (app(Sudo::class)->confirmed()) {
            return false;
        }

        $intended = route($returnRoute);

        session()->put('sudo.intended', $intended);
        StepUpReason::record('sudo', $reason, $intended);

        $this->redirectRoute('sudo', navigate: false);

        return true;
    }

    /** @return array<string, mixed> */
    public function with(ConsoleScope $scope, OrganizationApiKeys $keys): array
    {
        $organizationId = $scope->organizationId();

        /** @var Collection<int, OrganizationApiKey> $list */
        $list = $organizationId === null ? collect() : $keys->forOrganization($organizationId);

        return ['keys' => $list, 'assignableRoles' => MembershipRole::assignable(), 'freshKey' => $this->freshKey];
    }
}; ?>

<div>
    {{-- The console's page primitive rather than a hand-rolled h1: this page and
         Environment keys were the only two in the console rendering no eyebrow at
         all, so neither said which area of the rail you were standing in. --}}
    <x-page-header title="API keys"
                   subtitle="Machine credentials for the organization management API — list environments, invite members, read billing. Each key carries a role.">
        <x-slot:actions>
            <a href="/api/v1/openapi.yaml" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">API reference ↗</a>
        </x-slot:actions>
    </x-page-header>

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
                    <x-confirm-delete
                        :name="$key->name"
                        action="revokeKey('{{ $key->id }}')"
                        label="Revoke"
                        consequence="Any integration still presenting this key stops working immediately. This cannot be undone." />
                @endif
            </div>
        @empty
            <div class="cbx-empty"><div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div><h3>No API keys yet</h3><p>Create a key to reach the organization management API from your own services.</p></div>
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
