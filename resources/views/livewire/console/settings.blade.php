<?php

declare(strict_types=1);

use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use App\Platform\Appearance\Appearance;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Console › Settings — one component, both planes. What the administrator is
 * administering, and the details an integrator copies to wire sign-in up.
 *
 * The two pages were about different objects, which is why this pair looked like it
 * might not be one: the organization page was the ORGANIZATION's record — rename, slug,
 * type, id — and the environment page was the ENVIRONMENT's identity plus its OIDC
 * issuer and discovery URL. Neither offered what the other did.
 *
 * They are still one capability, and the console's own rule says how: an administrator of
 * the environment and an administrator of a tenant see the same product, and the
 * difference is what the environment holds IN ADDITION. So the organization's own
 * settings are here on both planes, bounded by whichever organization the scope resolves;
 * the environment's identity is here on the environment plane alone, because that record
 * is the control plane's; and the integration block — an issuer and a discovery URL that
 * are already served unauthenticated — is on both, because the organization admin wiring
 * an app is exactly who needed it and had nowhere to find it.
 */
new #[Layout('components.layouts.console', ['title' => 'Settings'])] class extends Component
{
    public string $orgName = '';

    /**
     * Second layer. The route's `env.admin` middleware is the primary gate and IS
     * re-run on Livewire actions (PersistentMiddlewareTest holds that), but this
     * console previously had NO in-component authorization at all — so when that
     * middleware was missing from the persistent list, every action here answered
     * unauthenticated. boot() rather than mount(): only boot() runs on each action,
     * and the organization page's gate lived in mount(), so a member demoted
     * mid-session kept an open page that still rendered.
     */
    public function boot(): void
    {
        $scope = app(ConsoleScope::class);

        if ($scope->mayAdminister()) {
            return;
        }

        // The organization plane's own courtesy, kept rather than flattened into a 403:
        // a member who lands here from a bookmark has a security centre of their own, and
        // this page is not a read-only echo of controls they cannot use.
        if ($scope->plane() === ConsolePlane::Organization) {
            $this->redirect(route('account'), navigate: true);

            return;
        }

        $scope->assertMayAdminister();
    }

    public function mount(): void
    {
        $organization = $this->organization();

        $this->orgName = $organization === null ? '' : $organization->name;
    }

    /**
     * Rename the organization the console is acting on.
     *
     * New to the environment plane, which could administer every organization in the
     * environment and not correct a typo in one's name without signing into its console.
     */
    public function rename(AuditLog $audit): void
    {
        $scope = app(ConsoleScope::class);
        $scope->assertMayAdminister();

        $this->validate(['orgName' => ['required', 'string', 'max:120']]);

        // requireOrganizationId(), not the nullable reader: with none resolved this write
        // would otherwise rename whichever organization a downstream default picked.
        $organizationId = $scope->requireOrganizationId();
        $organization = Organization::query()->findOrFail($organizationId);

        $to = trim($this->orgName);

        if ($to === $organization->name) {
            return;
        }

        $from = $organization->name;
        $organization->forceFill(['name' => $to])->save();

        // The scope's actor id, not the console's own idea of who is acting: the two
        // planes recorded ids from different tables for the same act.
        $audit->record(AuditEvent::forUser('organization.renamed', $scope->actorId(), $organization->id, [
            'from' => $from,
            'to' => $to,
        ]));

        $this->dispatch('toast', message: 'Organization name updated.');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(EnvironmentContext $environments): array
    {
        $scope = app(ConsoleScope::class);
        $onEnvironmentPlane = $scope->plane() === ConsolePlane::Environment;
        $organization = $this->organization();

        // ASKED, NOT REBUILT. This was `'https://'.request()->getHost()`, which is right
        // for a tenant environment on its own subdomain and right only by coincidence:
        // the platform root and the single-tenant shape keep the CONFIGURED issuer even
        // when they answer on another host, so the two part company exactly where nobody
        // is looking. One value with three derivations — this, `config('app.url')` on the
        // app page, and the resolver that discovery and the `iss` claim both use — is
        // three chances to hand a developer a value their SDK cannot verify against.
        $issuer = rtrim(app(IssuerResolver::class)->issuer(), '/');

        // Whose theme the branding card previews: the acting organization's own, or the
        // environment default it would inherit when there is no organization to speak of.
        $environment = $onEnvironmentPlane ? $this->environment($environments) : null;
        $brandingTarget = $organization ?? $environment;
        $brandingSettings = $brandingTarget === null ? [] : $brandingTarget->settings;

        return [
            'organization' => $organization,
            // The environment's own record, on the plane that holds it. An organization
            // admin has no business reading the control plane's identity, and no way to
            // act on it if they did.
            'environment' => $environment,
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            // The guided first run is an organization's, and its route only answers to a
            // subject session — so it is offered where it can actually be opened.
            'showSetupGuide' => ! $onEnvironmentPlane && $organization !== null,
            'appearance' => Appearance::fromSettings($brandingSettings),
            'issuer' => $issuer,
            'discovery' => $issuer.'/.well-known/openid-configuration',
        ];
    }

    /**
     * The organization being administered — the scope's, never a form field's.
     *
     * On the organization plane it is the member's own and nothing in the request can
     * change it; on the environment plane the scope re-validates the chosen id against
     * this environment on every read, so an id carried from elsewhere resolves to nothing.
     */
    private function organization(): ?Organization
    {
        $organizationId = app(ConsoleScope::class)->organizationId();

        return $organizationId === null ? null : Organization::query()->find($organizationId);
    }

    private function environment(EnvironmentContext $environments): ?Environment
    {
        $key = $environments->current()?->environmentKey();

        return $key !== null ? Environment::query()->find($key) : null;
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Settings" :help="\App\Platform\Help\HelpTopic::Settings">
        <x-slot:subtitle>
            What you are administering, and the details your apps need to integrate.
            @if ($environment === null)
                Manage your own security under
                <a href="{{ route('account') }}" class="underline" style="color:var(--accent-strong)">My account</a>.
            @endif
        </x-slot:subtitle>
    </x-page-header>

    {{-- Environment — the environment plane's own half, and only its own. --}}
    @if ($environment !== null)
        <section class="cbx-panel">
            <div class="cbx-panel-header">
                <div>
                    <h2 class="cbx-panel-title">Environment</h2>
                    <p class="cbx-panel-desc">The identity platform you operate. Every organization below lives inside it.</p>
                </div>
            </div>
            <div class="cbx-panel-body">
                <dl>
                    <div class="cbx-kv">
                        <dt>Name</dt>
                        <dd class="prose">
                            {{ $environment->name }}
                            @if ($environment->isSandbox())
                                <span class="badge badge-warn">Sandbox</span>
                            @endif
                        </dd>
                    </div>
                    <div class="cbx-kv"><dt>Environment ID</dt><dd>{{ $environment->id }}</dd></div>
                </dl>
            </div>
        </section>
    @endif

    {{-- Organization --}}
    <section class="cbx-panel">
        <div class="cbx-panel-header">
            <div>
                <h2 class="cbx-panel-title">Organization</h2>
                <p class="cbx-panel-desc">The organization this console is acting on.</p>
            </div>
        </div>
        <div class="cbx-panel-body">
            @if ($organization)
                {{-- Rename is new to the environment plane: an administrator who holds
                     every organization here could not correct a typo in one's name
                     without signing into that organization's own console. --}}
                <form wire:submit="rename" class="mb-4">
                    <label class="label" for="orgName">Name</label>
                    <div class="flex items-center gap-2">
                        <input wire:model="orgName" id="orgName" type="text" class="input" style="flex:1" maxlength="120" @error('orgName') aria-invalid="true" aria-describedby="orgName-error" @enderror>
                        <button type="submit" class="btn btn-primary shrink-0" wire:loading.attr="disabled" wire:target="rename">Rename</button>
                    </div>
                    @error('orgName') <p id="orgName-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </form>
                <dl>
                    <div class="cbx-kv"><dt>Slug</dt><dd>{{ $organization->slug }}</dd></div>
                    <div class="cbx-kv"><dt>Type</dt><dd class="prose"><span class="badge">{{ ucfirst($organization->type->value) }}</span></dd></div>
                    <div class="cbx-kv"><dt>Organization ID</dt><dd>{{ $organization->id }}</dd></div>
                </dl>
            @else
                <p class="text-sm" style="color:var(--faint)">Choose an organization to see and rename it.</p>
            @endif
        </div>
    </section>

    {{-- The way back to the setup guide. Dismissing it on the dashboard is meant to
         be reversible, and this is where someone looks for the switch. --}}
    @if ($showSetupGuide)
        <section class="cbx-panel">
            <div class="cbx-panel-header">
                <div>
                    <h2 class="cbx-panel-title">Setup guide</h2>
                    <p class="cbx-panel-desc">The steps worth doing for a new organization, with what each one gets you.</p>
                </div>
            </div>
            <div class="cbx-panel-body">
                <div class="flex items-center justify-between gap-4">
                    <p class="text-sm min-w-0" style="color:var(--muted-foreground)">Each step is measured from live state, so the guide always shows where this organization actually stands.</p>
                    <a href="{{ route('get-started') }}" wire:navigate class="btn btn-secondary shrink-0">Open guide <x-icon name="chevron" class="w-4 h-4" style="transform:rotate(-90deg)" /></a>
                </div>
            </div>
        </section>
    @endif

    {{-- Login branding --}}
    <section class="cbx-panel">
        <div class="cbx-panel-header">
            <div>
                <h2 class="cbx-panel-title">Login branding</h2>
                <p class="cbx-panel-desc">
                    @if ($organization)
                        Theme this organization's sign-in page. Its team signs in at
                        <a href="{{ route('login.branded', $organization->slug) }}" class="mono underline" style="color:var(--accent-strong)">/o/{{ $organization->slug }}/login</a>.
                    @else
                        The environment's default sign-in theme — inherited by every organization that has not set its own.
                    @endif
                </p>
            </div>
        </div>
        <div class="cbx-panel-body">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="grid grid-cols-2 grid-rows-2 w-10 h-10 rounded-lg overflow-hidden shrink-0" style="border:1px solid var(--border)" aria-hidden="true">
                        <span style="background:{{ $appearance->light->background }}"></span>
                        <span style="background:{{ $appearance->light->primary }}"></span>
                        <span style="background:{{ $appearance->dark->background }}"></span>
                        <span style="background:{{ $appearance->dark->primary }}"></span>
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{{ \App\Platform\Appearance\ThemePresets::get($appearance->preset)->label }} theme</p>
                        <p class="text-xs" style="color:var(--muted-foreground)">Presets, colours, corners &amp; type — edited with a live preview.</p>
                    </div>
                </div>
                <a href="{{ route($scopeRoute('appearance')) }}" class="btn btn-secondary shrink-0">Open editor <x-icon name="chevron" class="w-4 h-4" style="transform:rotate(-90deg)" /></a>
            </div>
        </div>
    </section>

    {{-- Integration. The environment plane's half, now on both: an organization admin
         wiring an OIDC client needed exactly these two URLs and had nowhere in their own
         console to find them. Both are already served unauthenticated. --}}
    <section class="cbx-panel">
        <div class="cbx-panel-header">
            <div>
                <h2 class="cbx-panel-title">Integration</h2>
                <p class="cbx-panel-desc">Point your OIDC client at these. Discovery exposes every endpoint automatically.</p>
            </div>
        </div>
        <div class="cbx-panel-body space-y-3">
            @foreach ([['Issuer', $issuer], ['OIDC discovery', $discovery]] as [$label, $value])
                <div class="rounded-xl border p-3" style="border-color:var(--border)">
                    <p class="text-xs" style="color:var(--faint)">{{ $label }}</p>
                    <div class="mt-1 flex items-center gap-2">
                        <code class="flex-1 min-w-0 truncate mono text-sm">{{ $value }}</code>
                        <x-copy-button :value="$value" class="btn-ghost" />
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</div>
