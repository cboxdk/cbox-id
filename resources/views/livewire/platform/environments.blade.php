<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Platform\OperatorEnvironment;
use Cbox\Id\Identity\Contracts\PasswordPolicyGuard;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Exceptions\PolicyViolation;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Crypto\Contracts\KeyManager;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\OrganizationType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * The operator's environment console: create planes, point the console at one,
 * and bootstrap a plane with its first organization and admin. Operators stand
 * above every environment, so switching has no identity guard — provisioning and
 * listing simply span every plane (the Environment model is not environment-owned).
 */
new #[Layout('components.layouts.workspace', ['title' => 'Environments', 'width' => '72rem'])] class extends Component
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
    public function boot(ConsoleScope $scope): void
    {
        abort_unless($scope->isPlatformOperator(), 404);
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

    public function switchTo(string $id, OperatorEnvironment $target): void
    {
        $environment = Environment::query()->find($id);

        if ($environment !== null) {
            $target->pointAt($environment->slug);
            $this->redirect(route('platform.environments'), navigate: false);
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
            'adminPassword' => 'required|string|max:200',
        ]);

        // Both of these questions can only be answered INSIDE the target environment:
        // email uniqueness is per-plane, and the password policy is the TENANT's, not
        // whichever plane the operator console happens to be sitting on. An operator
        // provisioning into a strict tenant is bound by that tenant's rules.
        $problem = $context->runAs($environment, function (): ?array {
            if (app(Subjects::class)->findByEmail($this->adminEmail) !== null) {
                return ['adminEmail', 'A user with that email already exists in this environment.'];
            }

            try {
                // No subject exists yet — this call CREATES the admin — so the
                // no-subject variant, named rather than implied by an omitted argument.
                app(PasswordPolicyGuard::class)->assertAcceptableForNewSubject($this->adminPassword);
            } catch (PolicyViolation $violation) {
                return ['adminPassword', $violation->getMessage()];
            }

            return null;
        });

        if ($problem !== null) {
            $this->addError($problem[0], $problem[1]);

            return;
        }

        $context->runAs($environment, function (): void {
            $subject = app(Subjects::class)->create($this->adminEmail, $this->adminName, $this->adminPassword);
            User::query()->where('email', $this->adminEmail)->update(['email_verified_at' => now()]);

            $org = app(Organizations::class)->create(
                new NewOrganization(name: $this->orgName, slug: Str::slug($this->orgName), type: OrganizationType::Customer),
            );
            app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
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

    /** @return array<string, mixed> */
    public function with(EnvironmentContext $context): array
    {
        $activeId = $context->current()?->environmentKey();

        $rows = $context->withoutScope(function () {
            /** @var Collection<string, int> $orgCounts */
            $orgCounts = Organization::query()->selectRaw('environment_id, count(*) as c')
                ->groupBy('environment_id')->pluck('c', 'environment_id');

            /** @var Collection<string, int> $userCounts */
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
    <x-page-header title="Environments" subtitle="Isolation planes above every organization. Create one, point the console at it, and bootstrap it with an admin.">
        <x-slot:actions>
            <button wire:click="$toggle('creating')" class="btn btn-primary">
                <x-icon name="plus" class="w-4 h-4" /> New environment
            </button>
        </x-slot:actions>
    </x-page-header>

    <p role="status" aria-live="polite" class="sr-only">{{ $environments->count() }} {{ \Illuminate\Support\Str::plural('environment', $environments->count()) }} found.</p>

    @if ($creating)
        <form wire:submit="create" class="card p-4 mb-5 mt-8 flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[14rem]">
                <label class="label" for="env-name">Name</label>
                <input wire:model="name" id="env-name" type="text" class="input" placeholder="Production" autofocus
                       @error('name') aria-invalid="true" aria-describedby="env-name-error" @enderror>
                @error('name') <p id="env-name-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="flex-1 min-w-[14rem]">
                <label class="label" for="env-domain">Custom domain <span style="color:var(--faint)">(optional)</span></label>
                <input wire:model="domain" id="env-domain" type="text" class="input" placeholder="id.acme.com"
                       aria-describedby="env-domain-hint @error('domain') env-domain-error @enderror"
                       @error('domain') aria-invalid="true" @enderror>
                <p id="env-domain-hint" class="mt-1 text-xs" style="color:var(--faint)">Recorded, not routed: the plane serves its own issuer until the domain is verified by DNS.</p>
                @error('domain') <p id="env-domain-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">
                <span class="spinner" wire:loading wire:target="create" aria-hidden="true"></span> Create
            </button>
            <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
        </form>
    @endif

    @if ($environments->isEmpty())
        <div class="cbx-empty mt-8">
            <div class="cbx-empty-icon"><x-icon name="layers" class="w-5 h-5" /></div>
            <h3>No environments yet</h3>
            <p>An environment is one isolation plane — its own users, keys, sign-in and issuer. Create your first one above, then bootstrap it with an organization and an admin.</p>
        </div>
    @else
        <div class="cbx-panel overflow-hidden mt-8">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Environment</th>
                            <th scope="col">Domain</th>
                            <th scope="col" class="text-right">Orgs</th>
                            <th scope="col" class="text-right">Users</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($environments as $env)
                            <tr wire:key="environment-{{ $env['id'] }}">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span aria-hidden="true" class="grid place-items-center rounded-md text-xs font-bold shrink-0"
                                              style="width:1.9rem;height:1.9rem;background:var(--accent-soft);color:var(--accent-strong)">
                                            {{ strtoupper(substr($env['name'], 0, 1)) }}
                                        </span>
                                        <div class="min-w-0">
                                            <p class="font-semibold">
                                                {{ $env['name'] }}
                                                @if ($env['id'] === $activeId)
                                                    <span class="cbx-pill cbx-pill--success align-middle ml-1"><span class="dot"></span>Target</span>
                                                @endif
                                            </p>
                                            <p class="text-xs font-mono" style="color:var(--faint)">{{ $env['slug'] }}</p>
                                        </div>
                                    </div>
                                </td>

                                <td style="color:var(--muted)">{{ $env['domain'] ?? 'None — served on the fallback host' }}</td>
                                <td class="text-right tabular-nums">{{ $env['orgs'] }}</td>
                                <td class="text-right tabular-nums">{{ $env['users'] }}</td>

                                <td class="text-right whitespace-nowrap">
                                    <button wire:click="startProvisioning('{{ $env['id'] }}')" class="btn btn-ghost btn-sm">Provision admin</button>
                                    @if ($env['id'] === $activeId)
                                        <span class="text-xs" style="color:var(--faint)">Target</span>
                                    @else
                                        {{-- Repoints EVERY subsequent read in this console at another
                                             plane, so it gets both a confirmation and a busy state: with
                                             neither, a slow switch looked like a dead button and the
                                             operator's next page was quietly about a different estate. --}}
                                        <button wire:click="switchTo('{{ $env['id'] }}')" class="btn btn-ghost btn-sm"
                                                wire:loading.attr="disabled" wire:target="switchTo('{{ $env['id'] }}')"
                                                wire:confirm="Point this console at {{ $env['name'] }}? Every page you open from now on — organizations, usage, tenant detail — reads that plane instead of the current one. Nothing is changed in either.">
                                            <span class="spinner" wire:loading wire:target="switchTo('{{ $env['id'] }}')" aria-hidden="true"></span>
                                            Target
                                        </button>
                                    @endif
                                </td>
                            </tr>

                            @if ($provisioningEnvId === $env['id'])
                                <tr wire:key="provision-{{ $env['id'] }}">
                                    <td colspan="5" style="background:var(--surface-2)">
                                        <form wire:submit="provisionAdmin">
                                            <p class="text-sm font-semibold mb-3">Bootstrap {{ $env['name'] }} — first organization &amp; admin</p>
                                            <div class="grid gap-3 sm:grid-cols-2">
                                                <div>
                                                    <label class="label" for="org-name-{{ $env['id'] }}">Organization name</label>
                                                    <input wire:model="orgName" id="org-name-{{ $env['id'] }}" type="text" class="input" placeholder="Acme Inc"
                                                           @error('orgName') aria-invalid="true" aria-describedby="org-name-error-{{ $env['id'] }}" @enderror>
                                                    @error('orgName') <p id="org-name-error-{{ $env['id'] }}" class="field-error" role="alert">{{ $message }}</p> @enderror
                                                </div>
                                                <div>
                                                    <label class="label" for="admin-name-{{ $env['id'] }}">Admin name</label>
                                                    <input wire:model="adminName" id="admin-name-{{ $env['id'] }}" type="text" class="input" placeholder="Ada Lovelace"
                                                           @error('adminName') aria-invalid="true" aria-describedby="admin-name-error-{{ $env['id'] }}" @enderror>
                                                    @error('adminName') <p id="admin-name-error-{{ $env['id'] }}" class="field-error" role="alert">{{ $message }}</p> @enderror
                                                </div>
                                                <div>
                                                    <label class="label" for="admin-email-{{ $env['id'] }}">Admin email</label>
                                                    <input wire:model="adminEmail" id="admin-email-{{ $env['id'] }}" type="email" class="input" placeholder="admin@acme.com"
                                                           @error('adminEmail') aria-invalid="true" aria-describedby="admin-email-error-{{ $env['id'] }}" @enderror>
                                                    @error('adminEmail') <p id="admin-email-error-{{ $env['id'] }}" class="field-error" role="alert">{{ $message }}</p> @enderror
                                                </div>
                                                <div>
                                                    <label class="label" for="admin-password-{{ $env['id'] }}">Admin password</label>
                                                    <input wire:model="adminPassword" id="admin-password-{{ $env['id'] }}" type="password" autocomplete="new-password" class="input" placeholder="At least 12 characters"
                                                           @error('adminPassword') aria-invalid="true" aria-describedby="admin-password-error-{{ $env['id'] }}" @enderror>
                                                    @error('adminPassword') <p id="admin-password-error-{{ $env['id'] }}" class="field-error" role="alert">{{ $message }}</p> @enderror
                                                </div>
                                            </div>
                                            <div class="mt-3 flex gap-2">
                                                <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="provisionAdmin">
                                                    <span class="spinner" wire:loading wire:target="provisionAdmin" aria-hidden="true"></span> Provision
                                                </button>
                                                <button type="button" wire:click="$set('provisioningEnvId', null)" class="btn btn-ghost btn-sm">Cancel</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
