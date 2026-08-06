<?php

declare(strict_types=1);

use App\Platform\OrganizationActivity;
use App\Platform\OrganizationCapabilities;
use App\Platform\Console\ConsoleScope;
use App\Platform\StepUpReason;
use App\Platform\Sudo;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Identity platform › Environment keys — issue and revoke ENVIRONMENT management-plane keys
 * (`cbid_env_…`), the machine credential apps use to provision organizations and
 * users inside one environment. Distinct from account keys: an environment key is
 * bound to a single environment and carries fine-grained scopes, not a role.
 *
 * High-privilege (a key can provision identities), so only roles that manage
 * environments may mint or revoke them, and only for an environment they can reach.
 * The plaintext is shown exactly once.
 */
new #[Layout('components.layouts.app', ['title' => 'Environment keys'])] class extends Component
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

    public function mount(ConsoleScope $scope, Memberships $members): void
    {
        // ONE question now. It used to be two — the scope decided ADMISSION (does the
        // organization own identity providers, and may this person manage its environments)
        // and the member row was what the page READ — because authority lived in two places
        // and the scope answering yes did not hand the row over. The scope answers both.
        if ($scope->capabilities()?->canManageEnvironments() !== true) {
            $this->redirect(route('projects'));

            return;
        }

        // Default to read-only scopes — an admin opts into write explicitly.
        $this->newKeyScopes = [
            EnvironmentApiScope::OrganizationsRead->value,
            EnvironmentApiScope::UsersRead->value,
        ];

        $ids = $this->reachable($scope, $members);
        $first = Environment::query()->whereIn('id', $ids)->orderBy('created_at')->value('id');
        $this->selectedEnvironment = is_string($first) ? $first : '';
    }

    public function createKey(ConsoleScope $scope, Memberships $members, EnvironmentApiKeys $keys, OrganizationActivity $activity): void
    {
        // Authorization FIRST, then the step-up. It ran the other way round, which handed
        // a member who may not mint anything a password prompt and then refused them in
        // silence once they had typed it — and taught everybody else that the prompt is
        // something you get past rather than something that means what it says.
        if (! $this->guard($scope, $members)) {
            return;
        }

        if ($this->requiresSudo('environment-keys', 'An environment API key reads and writes this environment\'s organizations and people over the API, and its value is shown once.')) {
            return;
        }

        $this->validate([
            'newKeyName' => ['required', 'string', 'max:120'],
            'newKeyScopes' => ['required', 'array', 'min:1'],
            'newKeyScopes.*' => ['in:'.implode(',', EnvironmentApiScope::all())],
        ]);

        $issued = $keys->issue($this->selectedEnvironment, trim($this->newKeyName), array_values($this->newKeyScopes));

        $organizationId = $scope->organizationId();
        if ($organizationId !== null) {
            $activity->record($organizationId, 'organization.environment_key_created', $scope->actorId(),
                targetType: 'environment', targetId: $this->selectedEnvironment,
                context: ['name' => trim($this->newKeyName), 'scopes' => array_values($this->newKeyScopes)],
                request: request());
        }

        $this->freshKey = $issued->plaintext;
        $this->reset('newKeyName');
    }

    public function revokeKey(string $id, ConsoleScope $scope, Memberships $members, EnvironmentApiKeys $keys, OrganizationActivity $activity): void
    {
        // Revoking is as consequential as issuing, and was not gated. A stolen but
        // non-sudo session could not MINT persistence — create requires the step-up — but
        // it could destroy the machine credentials that run provisioning and automation,
        // which is a denial of service the same session was otherwise held back from.
        if (! $this->guard($scope, $members)) {
            return;
        }

        if ($this->requiresSudo('environment-keys', 'Revoking an environment key stops whatever is using it, immediately.')) {
            return;
        }

        // Only revoke a key that belongs to the selected (and accessible) environment.
        if ($keys->forEnvironment($this->selectedEnvironment)->firstWhere('id', $id) !== null) {
            $keys->revoke($this->selectedEnvironment, $id);

            $organizationId = $scope->organizationId();
            if ($organizationId !== null) {
                $activity->record($organizationId, 'organization.environment_key_revoked', $scope->actorId(),
                    targetType: 'environment', targetId: $this->selectedEnvironment,
                    context: ['key_id' => $id], request: request());
            }

            $this->dispatch('toast', message: 'Environment key revoked.');
        }
    }

    /** The member manages environments AND the selected one is theirs to reach. */
    private function guard(ConsoleScope $scope, Memberships $members): bool
    {
        if ($scope->capabilities()?->canManageEnvironments() !== true || $this->selectedEnvironment === '') {
            return false;
        }

        return in_array($this->selectedEnvironment, $this->reachable($scope, $members), true);
    }

    /**
     * The environments this administrator may actually reach.
     *
     * `accessibleEnvironmentIds()` takes (organization, subject) rather than a member row,
     * because the row that carried both is two rows now. Both halves come from the SCOPE, so
     * a page cannot ask about one person's organization and another person's grants.
     *
     * @return list<string>
     */
    private function reachable(ConsoleScope $scope, Memberships $members): array
    {
        $organizationId = $scope->organizationId();
        $actorId = $scope->actorId();

        return $organizationId === null || $actorId === ''
            ? []
            : $members->accessibleEnvironmentIds($organizationId, $actorId);
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
    public function with(ConsoleScope $scope, Memberships $members, EnvironmentApiKeys $keys): array
    {
        $ids = $this->reachable($scope, $members);

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
    {{-- See api-keys.blade.php: these two were the account console's only pages with a
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
