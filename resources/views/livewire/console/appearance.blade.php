<?php

declare(strict_types=1);

use App\Platform\Appearance\Appearance;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Console › Appearance — one component, both planes. The hosted-sign-in theme: presets,
 * colours, corners and type, edited against a live preview.
 *
 * The two pages looked like the same feature and were not quite: the organization one
 * themed an ORGANIZATION (which overrides the environment default), the environment one
 * themed the ENVIRONMENT's default (which every organization in it inherits). That
 * difference is a real capability, not a plane detail — so it survives the merge as an
 * explicit choice rather than being implied by which console you happened to open, and
 * it is offered and enforced on the environment plane alone. An organization admin who
 * could reach it would be re-theming the sign-in page of every other tenant here.
 *
 * The organization page also refused an unreadable palette and the environment page did
 * not, so an operator could set an environment default that no tenant's users could read.
 * That gate is on both now.
 */
new #[Layout('components.layouts.console', ['title' => 'Appearance'])] class extends Component
{
    /** @var array<string, mixed> */
    public array $appearance = [];

    /**
     * Theme the environment's default rather than an organization's own.
     *
     * The capability the environment console's page WAS, kept as its landing state on
     * that plane: a merge that silently retargeted "Appearance" at whichever organization
     * happened to be picked would have an operator re-theming one tenant while believing
     * they were setting the default for all of them.
     */
    public bool $environmentDefault = false;

    /**
     * Second layer. The route's `env.admin` middleware is the primary gate and IS
     * re-run on Livewire actions (PersistentMiddlewareTest holds that), but this
     * console previously had NO in-component authorization at all — so when that
     * middleware was missing from the persistent list, every action here answered
     * unauthenticated. boot() rather than mount(): only boot() runs on each action.
     */
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    public function mount(): void
    {
        $this->environmentDefault = app(ConsoleScope::class)->plane() === ConsolePlane::Environment;

        $this->seed();
    }

    /** Re-seed the editor when the target changes, so the preview shows what is about to be written. */
    public function updatedEnvironmentDefault(): void
    {
        $this->seed();
    }

    /**
     * @param  array<string, mixed>  $theme
     *
     * @throws AuthorizationException
     */
    public function save(array $theme, Organizations $organizations, EnvironmentContext $environments): void
    {
        app(ConsoleScope::class)->assertMayAdminister();

        $appearance = Appearance::fromArray($theme);

        // Refuse an unreadable palette rather than warn about one. hex() validates the
        // FORMAT, so nothing stopped a "light" mode with a near-black background — and
        // the people who then cannot read the sign-in page are not the admin choosing the
        // colours, they are that organization's users. A warning the saver can click past
        // puts the consequence on someone who never saw it.
        $failures = array_merge(
            array_map(fn (string $why): string => 'Light mode: '.$why, $appearance->light->contrastFailures()),
            array_map(fn (string $why): string => 'Dark mode: '.$why, $appearance->dark->contrastFailures()),
        );

        if ($failures !== []) {
            $this->dispatch('toast', message: implode(' ', $failures), severity: 'error');

            return;
        }

        $logo = self::normalizeLogo($theme['logo'] ?? null);

        $payload = [
            'appearance' => $appearance->toArray(),
            'brand_color' => $appearance->light->primary,
            'brand_logo_url' => $logo,
        ];

        if ($this->environmentDefault) {
            // Refused rather than quietly downgraded to the organization: the flag is not
            // rendered on the organization plane, so its arrival there is a forged wire
            // payload, and treating a forgery as a typo is how a control stops being one.
            $this->assertMayThemeTheEnvironment();

            $environment = $this->environment($environments);

            if ($environment === null) {
                return;
            }

            $environment->settings = array_merge($environment->settings, $payload);
            $environment->save();

            $this->seed();
            $this->dispatch('toast', message: 'Environment appearance saved.');

            return;
        }

        // requireOrganizationId(), not the nullable reader: with no organization resolved
        // a write would otherwise land wherever a downstream default pointed.
        $organizations->updateSettings(app(ConsoleScope::class)->requireOrganizationId(), $payload);

        $this->seed();
        $this->dispatch('toast', message: 'Appearance saved.');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $scope = app(ConsoleScope::class);

        return [
            // THE VIEW HALF. The environment toggle is a capability, so the markup asks
            // the scope rather than assuming the plane it was written for.
            'mayThemeEnvironment' => $scope->plane() === ConsolePlane::Environment,
            'organizationName' => $this->organization()?->name,
            // Nothing to theme: an environment administrator who has not chosen an
            // organization, or a member who belongs to none.
            'hasTarget' => $this->environmentDefault || $scope->organizationId() !== null,
        ];
    }

    /** Load the editor with whichever thing is currently being themed. */
    private function seed(): void
    {
        $target = $this->environmentDefault
            ? $this->environment(app(EnvironmentContext::class))
            : $this->organization();

        $settings = $target === null ? [] : $target->settings;
        $name = $target === null ? '' : $target->name;

        $this->appearance = Appearance::fromSettings($settings)->toArray();
        $this->appearance['logo'] = is_string($settings['brand_logo_url'] ?? null) ? $settings['brand_logo_url'] : '';
        $this->appearance['name'] = $name;
    }

    /**
     * The organization being themed — the scope's, never a form field's.
     *
     * On the organization plane that is the member's own and nothing in the request can
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

    /** @throws AuthorizationException */
    private function assertMayThemeTheEnvironment(): void
    {
        if (app(ConsoleScope::class)->plane() !== ConsolePlane::Environment) {
            throw new AuthorizationException('Only an environment administrator may change the environment default theme.');
        }
    }

    private static function normalizeLogo(mixed $value): ?string
    {
        $logo = is_string($value) ? trim($value) : '';

        return $logo !== '' && filter_var($logo, FILTER_VALIDATE_URL) !== false && str_starts_with($logo, 'https://')
            ? $logo
            : null;
    }
}; ?>

<div>
    @if ($mayThemeEnvironment)
        {{-- The environment plane's own capability, made explicit. It was never an
             organization: it is the default every organization here inherits, and the
             console chrome's organization picker has no way to say that. --}}
        <div class="card p-4 mb-6">
            <p class="label">What you are theming</p>
            <div class="mt-2 flex flex-wrap items-center gap-4">
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="radio" wire:model.live="environmentDefault" value="1" name="appearance-target">
                    <span>Environment default <span style="color:var(--muted-foreground)">— inherited by every organization that has not set its own</span></span>
                </label>
                @if ($organizationName !== null)
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="radio" wire:model.live="environmentDefault" value="0" name="appearance-target">
                        <span>{{ $organizationName }} <span style="color:var(--muted-foreground)">— overrides the default for this organization alone</span></span>
                    </label>
                @endif
            </div>
        </div>
    @endif

    @if ($hasTarget)
        <x-theme-editor :appearance="$appearance" :scope="$environmentDefault ? 'environment' : 'organization'"
            title="Appearance"
            :subtitle="$environmentDefault
                ? 'Your environment\'s default sign-in theme. Every organization inherits it unless it sets its own.'
                : 'This organization\'s sign-in theme — it overrides the environment default. Changes preview live and apply to its hosted sign-in.'" />
    @else
        <x-page-header title="Appearance" :help="\App\Platform\Help\HelpTopic::Appearance"
                       subtitle="The hosted sign-in theme: presets, colours, corners and type." />
        <x-empty-state icon="settings" title="Nothing to theme yet"
                       body="Choose an organization to theme its sign-in page, or switch to the environment default above to set the theme every organization inherits." />
    @endif
</div>
