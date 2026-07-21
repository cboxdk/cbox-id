<?php

declare(strict_types=1);

use App\Platform\Appearance\Appearance;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment › Appearance. The environment's DEFAULT hosted-sign-in theme — every
 * organization in it inherits this unless it sets its own (org overrides env). Same
 * shared <x-theme-editor>; persists to the environment's settings. Reachable only via
 * the env-admin console (route middleware), which grants full control of the env.
 */
new #[Layout('components.layouts.environment', ['title' => 'Appearance'])] class extends Component
{
    /** @var array<string, mixed> */
    public array $appearance = [];

    public function mount(EnvironmentContext $environments): void
    {
        $env = $this->environment($environments);
        $settings = is_array($env?->settings) ? $env->settings : [];

        $this->appearance = Appearance::fromSettings($settings)->toArray();
        $this->appearance['logo'] = is_string($settings['brand_logo_url'] ?? null) ? $settings['brand_logo_url'] : '';
        $this->appearance['name'] = $env?->name ?? '';
    }

    /**
     * @param  array<string, mixed>  $theme
     */
    public function save(array $theme, EnvironmentContext $environments): void
    {
        $env = $this->environment($environments);
        if ($env === null) {
            return;
        }

        $appearance = Appearance::fromArray($theme);
        $logo = self::normalizeLogo($theme['logo'] ?? null);

        $env->settings = array_merge(is_array($env->settings) ? $env->settings : [], [
            'appearance' => $appearance->toArray(),
            'brand_color' => $appearance->light->primary,
            'brand_logo_url' => $logo,
        ]);
        $env->save();

        $this->appearance = $appearance->toArray();
        $this->appearance['logo'] = $logo ?? '';
        $this->appearance['name'] = $env->name;

        $this->dispatch('toast', message: 'Environment appearance saved.');
    }

    private function environment(EnvironmentContext $environments): ?Environment
    {
        $key = $environments->current()?->environmentKey();

        return $key !== null ? Environment::query()->find($key) : null;
    }

    private static function normalizeLogo(mixed $value): ?string
    {
        $logo = is_string($value) ? trim($value) : '';

        return $logo !== '' && filter_var($logo, FILTER_VALIDATE_URL) !== false && str_starts_with($logo, 'https://')
            ? $logo
            : null;
    }
}; ?>

<x-theme-editor :appearance="$appearance" scope="environment"
    title="Appearance"
    subtitle="Your environment's default sign-in theme. Every organization inherits it unless it sets its own." />
