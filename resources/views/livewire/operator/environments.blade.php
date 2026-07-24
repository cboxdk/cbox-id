<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Crypto\Contracts\KeyManager;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * The operator's environment console: create planes, point the console at one,
 * and bootstrap a plane with its first organization and admin. Operators stand
 * above every environment, so switching has no identity guard — provisioning and
 * listing simply span every plane (the Environment model is not environment-owned).
 */
new #[Layout('components.layouts.operator', ['title' => 'Environments'])] class extends Component
{
    public bool $creating = false;

    public string $name = '';

    public string $domain = '';

    // Plane-bootstrap form.
    public ?string $provisioningEnvId = null;

    public string $orgName = '';

    public string $adminName = '';

    public string $adminEmail = '';

    public string $adminPassword = '';

    /**
     * Runs on every request — mount AND each Livewire action — so a suspended or
     * signed-out operator is refused even on a wire:click, not just the initial
     * GET (Livewire only re-runs persistent middleware on update requests).
     */
    public function boot(OperatorAuth $auth): void
    {
        abort_unless($auth->check(), 403);
    }

    public function create(EnvironmentContext $context, KeyManager $keys): void
    {
        $this->validate([
            'name' => 'required|string|max:190',
            'domain' => 'nullable|string|max:190|regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i',
        ]);

        $domain = $this->domain !== '' ? strtolower($this->domain) : null;

        if ($domain !== null && Environment::query()->where('domain', $domain)->exists()) {
            $this->addError('domain', 'That domain is already routed to another environment.');

            return;
        }

        // A domain set here is NOT verified — no DNS proof has been shown for it. The
        // per-environment issuer deliberately trusts a custom domain only once
        // domain_verified_at is stamped, so writing `domain` now would route the host
        // while discovery kept advertising the fallback issuer: every conformant OIDC
        // client (including our own SDKs) rejects that mismatch per RFC 8414 §3.3, and
        // the environment is silently unusable from the moment it is created.
        //
        // So create it WITHOUT the domain and let the operator run the same DNS-TXT
        // verification flow every other writer uses. One door, one invariant.
        $environment = Environment::query()->create([
            'name' => $this->name,
            'slug' => $this->uniqueSlug($this->name),
            'status' => 'active',
        ]);

        // Warm the new plane's own signing key so its JWKS/discovery is live now.
        $context->runAs($environment, fn () => $keys->activeSigningKey());

        $this->reset('name', 'domain', 'creating');

        $this->dispatch('toast', message: $domain === null
            ? 'Environment "'.$environment->name.'" created.'
            : 'Environment "'.$environment->name.'" created. Add '.$domain.' from the environment\'s domain settings to verify it by DNS — an unverified domain cannot be its issuer.');
    }

    public function switchTo(string $id): void
    {
        $environment = Environment::query()->find($id);

        if ($environment !== null) {
            session()->put(OperatorAuth::ENV_KEY, $environment->slug);
            $this->redirect(route('operator.environments'), navigate: false);
        }
    }

    public function startProvisioning(string $id): void
    {
        $this->reset('orgName', 'adminName', 'adminEmail', 'adminPassword');
        $this->provisioningEnvId = $id;
    }

    /**
     * Bootstrap a plane: create its first organization and an owner admin. This
     * is how an operator seeds a brand-new environment so real users can sign in.
     */
    public function provisionAdmin(EnvironmentContext $context): void
    {
        $environment = $this->provisioningEnvId !== null
            ? Environment::query()->find($this->provisioningEnvId)
            : null;

        abort_if($environment === null, 404);

        $this->validate([
            'orgName' => 'required|string|max:190',
            'adminName' => 'required|string|max:190',
            'adminEmail' => 'required|email|max:190',
            'adminPassword' => 'required|string|min:12',
        ]);

        // The email must be unique within the target plane (checked in its scope).
        $taken = $context->runAs($environment, fn (): bool => app(Subjects::class)->findByEmail($this->adminEmail) !== null);
        if ($taken) {
            $this->addError('adminEmail', 'A user with that email already exists in this environment.');

            return;
        }

        $context->runAs($environment, function (): void {
            $subject = app(Subjects::class)->create($this->adminEmail, $this->adminName, $this->adminPassword);
            User::query()->where('email', $this->adminEmail)->update(['email_verified_at' => now()]);

            $org = app(Organizations::class)->create(
                new NewOrganization(name: $this->orgName, slug: Str::slug($this->orgName), type: OrganizationType::Customer),
            );
            app(Memberships::class)->add($org->id, $subject->id, 'owner');
        });

        $name = $environment->name;
        $this->reset('orgName', 'adminName', 'adminEmail', 'adminPassword', 'provisioningEnvId');
        $this->dispatch('toast', message: 'Provisioned an organization and admin in "'.$name.'".');
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

        $rows = $context->withoutScope(function () {
            $orgCounts = Organization::query()->selectRaw('environment_id, count(*) as c')
                ->groupBy('environment_id')->pluck('c', 'environment_id');
            $userCounts = User::query()->selectRaw('environment_id, count(*) as c')
                ->groupBy('environment_id')->pluck('c', 'environment_id');

            return Environment::query()->orderBy('created_at')->get()
                ->map(fn (Environment $e): array => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'slug' => $e->slug,
                    'domain' => $e->domain,
                    'orgs' => (int) ($orgCounts[$e->id] ?? 0),
                    'users' => (int) ($userCounts[$e->id] ?? 0),
                ]);
        });

        return ['environments' => $rows, 'activeId' => $activeId];
    }
}; ?>

<div>
    <div class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">Platform</p>
            <h1 class="cbx-page-title">Environments</h1>
            <p class="cbx-page-desc">Isolation planes above every organization. Create one, point the console at it, and bootstrap it with an admin.</p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="$toggle('creating')" class="btn btn-primary">
                <x-icon name="plus" class="w-4 h-4" /> New environment
            </button>
        </div>
    </div>

    @if ($creating)
        <form wire:submit="create" class="card p-4 mb-5 mt-8 flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[14rem]">
                <label class="label" for="env-name">Name</label>
                <input wire:model="name" id="env-name" type="text" class="input" placeholder="Production" autofocus>
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="flex-1 min-w-[14rem]">
                <label class="label" for="env-domain">Custom domain <span style="color:var(--faint)">(optional)</span></label>
                <input wire:model="domain" id="env-domain" type="text" class="input" placeholder="id.acme.com">
                @error('domain') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Create</button>
            <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
        </form>
    @endif

    <div class="cbx-panel overflow-hidden mt-8">
        <div class="hidden sm:grid px-5 py-3 border-b text-xs font-medium uppercase tracking-wide"
             style="border-color:var(--border);color:var(--faint);grid-template-columns:2fr 1.5fr 1fr 1fr auto">
            <span>Environment</span><span>Domain</span><span>Orgs</span><span>Users</span><span></span>
        </div>

        @foreach ($environments as $env)
            <div class="px-5 py-4 border-b flex flex-col gap-3 sm:grid sm:items-center sm:gap-4"
                 style="border-color:var(--border);grid-template-columns:2fr 1.5fr 1fr 1fr auto">
                <div class="flex items-center gap-3 min-w-0">
                    <span aria-hidden="true" class="grid place-items-center rounded-md text-xs font-bold shrink-0"
                          style="width:1.9rem;height:1.9rem;background:var(--accent-soft);color:var(--accent)">
                        {{ strtoupper(substr($env['name'], 0, 1)) }}
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold truncate">
                            {{ $env['name'] }}
                            @if ($env['id'] === $activeId)
                                <span class="cbx-pill cbx-pill--success align-middle ml-1"><span class="dot"></span>Target</span>
                            @endif
                        </p>
                        <p class="text-xs font-mono truncate" style="color:var(--faint)">{{ $env['slug'] }}</p>
                    </div>
                </div>

                <div class="text-sm truncate" style="color:var(--muted)">{{ $env['domain'] ?? '—' }}</div>
                <div class="text-sm"><span class="sm:hidden" style="color:var(--faint)">Orgs: </span>{{ $env['orgs'] }}</div>
                <div class="text-sm"><span class="sm:hidden" style="color:var(--faint)">Users: </span>{{ $env['users'] }}</div>

                <div class="flex items-center gap-2 sm:justify-self-end">
                    <button wire:click="startProvisioning('{{ $env['id'] }}')" class="btn btn-ghost btn-sm">Provision admin</button>
                    @if ($env['id'] === $activeId)
                        <span class="text-xs" style="color:var(--faint)">Target</span>
                    @else
                        <button wire:click="switchTo('{{ $env['id'] }}')" class="btn btn-ghost btn-sm">Target</button>
                    @endif
                </div>
            </div>

            @if ($provisioningEnvId === $env['id'])
                <form wire:submit="provisionAdmin" class="px-5 py-4 border-b" style="border-color:var(--border);background:var(--surface-2)">
                    <p class="text-sm font-semibold mb-3">Bootstrap {{ $env['name'] }} — first organization &amp; admin</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="label" for="org-name-{{ $env['id'] }}">Organization name</label>
                            <input wire:model="orgName" id="org-name-{{ $env['id'] }}" type="text" class="input" placeholder="Acme Inc">
                            @error('orgName') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label" for="admin-name-{{ $env['id'] }}">Admin name</label>
                            <input wire:model="adminName" id="admin-name-{{ $env['id'] }}" type="text" class="input" placeholder="Ada Lovelace">
                            @error('adminName') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label" for="admin-email-{{ $env['id'] }}">Admin email</label>
                            <input wire:model="adminEmail" id="admin-email-{{ $env['id'] }}" type="email" class="input" placeholder="admin@acme.com">
                            @error('adminEmail') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label" for="admin-password-{{ $env['id'] }}">Admin password</label>
                            <input wire:model="adminPassword" id="admin-password-{{ $env['id'] }}" type="password" autocomplete="new-password" class="input" placeholder="At least 12 characters">
                            @error('adminPassword') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled">Provision</button>
                        <button type="button" wire:click="$set('provisioningEnvId', null)" class="btn btn-ghost btn-sm">Cancel</button>
                    </div>
                </form>
            @endif
        @endforeach

        @if ($environments->isEmpty())
            <div class="px-5 py-10 text-center text-sm" style="color:var(--faint)">
                No environments yet. Create your first plane to get started.
            </div>
        @endif
    </div>
</div>
