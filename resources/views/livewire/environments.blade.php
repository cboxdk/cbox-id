<?php

declare(strict_types=1);

use App\Http\Middleware\SetEnvironment;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Crypto\Contracts\KeyManager;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environments — the platform's hard outer isolation boundary (WorkOS-style
 * production/staging planes, or a plane per product). Provisioning happens
 * OUTSIDE any environment scope: the Environment model is the boundary, not an
 * environment-owned row, so listing and creating are never filtered.
 */
new #[Layout('components.layouts.app', ['title' => 'Environments'])] class extends Component
{
    public bool $creating = false;

    #[Validate('required|string|max:190')]
    public string $name = '';

    // An optional vanity host that resolves straight to this environment
    // (id.acme.com → the acme production plane). Left blank, the environment is
    // reached by the console switcher only.
    #[Validate('nullable|string|max:190|regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i')]
    public string $domain = '';

    public function create(EnvironmentContext $context, KeyManager $keys): void
    {
        $this->validate();

        $slug = $this->uniqueSlug($this->name);
        $domain = $this->domain !== '' ? strtolower($this->domain) : null;

        if ($domain !== null && Environment::query()->where('domain', $domain)->exists()) {
            $this->addError('domain', 'That domain is already routed to another environment.');

            return;
        }

        $environment = Environment::query()->create([
            'name' => $this->name,
            'slug' => $slug,
            'domain' => $domain,
            'status' => 'active',
        ]);

        // Warm the environment's own signing key so its JWKS/discovery is live
        // the moment it exists — keys are per-environment and generated lazily,
        // so provision one now inside the new plane rather than on first token.
        $context->runAs($environment, fn () => $keys->activeSigningKey());

        $this->reset('name', 'domain', 'creating');
        session()->flash('status', 'Environment "'.$environment->name.'" created.');
    }

    /**
     * Point the console at another environment. Users, orgs and keys are all
     * environment-owned and deny-by-default, so an operator without a presence
     * in the target simply sees empty state — never another plane's data.
     */
    public function switchTo(string $id, EnvironmentContext $context): void
    {
        $environment = Environment::query()->find($id);

        if ($environment === null) {
            return;
        }

        // Only switch into a plane where the operator has an identity — otherwise
        // deny-by-default would sign them out on the next request. Explain instead.
        $email = app(\App\Platform\CurrentUser::class)->email();
        $hasIdentity = $email !== null && $context->runAs(
            $environment,
            fn (): bool => User::query()->where('email', $email)->exists(),
        );

        if (! $hasIdentity) {
            session()->flash('status', 'You do not have an account in the '.$environment->name.' environment yet.');
            $this->redirect(route('environments'), navigate: false);

            return;
        }

        session()->put(SetEnvironment::SESSION_KEY, $environment->slug);
        $this->redirect(route('dashboard'), navigate: false);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'env';
        $slug = $base;
        $n = 2;

        while (Environment::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    public function with(EnvironmentContext $context): array
    {
        $activeId = $context->current()?->environmentKey();

        // Counts span every environment, so suspend the scope: the whole point
        // of this screen is the cross-plane overview only the operator sees.
        $rows = $context->withoutScope(function () {
            $orgCounts = Organization::query()
                ->selectRaw('environment_id, count(*) as c')->groupBy('environment_id')
                ->pluck('c', 'environment_id');
            $userCounts = User::query()
                ->selectRaw('environment_id, count(*) as c')->groupBy('environment_id')
                ->pluck('c', 'environment_id');

            return Environment::query()->orderBy('created_at')->get()
                ->map(fn (Environment $e): array => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'slug' => $e->slug,
                    'domain' => $e->domain,
                    'status' => $e->status,
                    'orgs' => (int) ($orgCounts[$e->id] ?? 0),
                    'users' => (int) ($userCounts[$e->id] ?? 0),
                    'created' => $e->created_at,
                ]);
        });

        return [
            'environments' => $rows,
            'activeId' => $activeId,
        ];
    }
}; ?>

<div>
    <x-page-header title="Environments"
                   subtitle="Isolation planes above every organization — production, staging, or one per product. Data never crosses an environment.">
        <x-slot:actions>
            <button wire:click="$toggle('creating')" class="btn btn-primary">
                <x-icon name="plus" class="w-4 h-4" /> New environment
            </button>
        </x-slot:actions>
    </x-page-header>

    @if ($creating)
        <form wire:submit="create" class="card p-4 mb-5 flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[14rem]">
                <label class="label" for="env-name">Name</label>
                <input wire:model="name" id="env-name" type="text" class="input" placeholder="Production" autofocus>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex-1 min-w-[14rem]">
                <label class="label" for="env-domain">Custom domain <span style="color:var(--faint)">(optional)</span></label>
                <input wire:model="domain" id="env-domain" type="text" class="input" placeholder="id.acme.com">
                @error('domain') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Create</button>
            <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
        </form>
    @endif

    <div class="card overflow-hidden">
        <div class="hidden sm:grid px-5 py-3 border-b text-xs font-medium uppercase tracking-wide"
             style="border-color:var(--border);color:var(--faint);grid-template-columns:2fr 1.5fr 1fr 1fr auto">
            <span>Environment</span><span>Domain</span><span>Organizations</span><span>Users</span><span></span>
        </div>

        @foreach ($environments as $env)
            <div class="px-5 py-4 border-b flex flex-col gap-3 sm:grid sm:items-center sm:gap-4"
                 style="border-color:var(--border);grid-template-columns:2fr 1.5fr 1fr 1fr auto">
                <div class="flex items-center gap-3 min-w-0">
                    <span aria-hidden="true" class="grid place-items-center rounded-md text-xs font-bold shrink-0"
                          style="width:1.9rem;height:1.9rem;background:var(--accent);color:var(--accent-fg)">
                        {{ strtoupper(substr($env['name'], 0, 1)) }}
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold truncate">
                            {{ $env['name'] }}
                            @if ($env['id'] === $activeId)
                                <span class="badge badge-success align-middle ml-1">Current</span>
                            @endif
                        </p>
                        <p class="text-xs font-mono truncate" style="color:var(--faint)">{{ $env['slug'] }}</p>
                    </div>
                </div>

                <div class="text-sm truncate" style="color:var(--muted)">
                    {{ $env['domain'] ?? '—' }}
                </div>
                <div class="text-sm"><span class="sm:hidden" style="color:var(--faint)">Organizations: </span>{{ $env['orgs'] }}</div>
                <div class="text-sm"><span class="sm:hidden" style="color:var(--faint)">Users: </span>{{ $env['users'] }}</div>

                <div class="sm:justify-self-end">
                    @if ($env['id'] === $activeId)
                        <span class="text-xs" style="color:var(--faint)">Active</span>
                    @else
                        <button wire:click="switchTo('{{ $env['id'] }}')" class="btn btn-ghost btn-sm">Use</button>
                    @endif
                </div>
            </div>
        @endforeach

        @if ($environments->isEmpty())
            <div class="px-5 py-10 text-center text-sm" style="color:var(--faint)">
                No environments yet. Create your first plane to get started.
            </div>
        @endif
    </div>
</div>
