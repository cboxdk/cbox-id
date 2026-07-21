<?php

declare(strict_types=1);

use App\Platform\Appearance\Appearance;
use App\Platform\CurrentUser;
use Cbox\Id\Organization\Contracts\Organizations;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Organization › Appearance. The org's own hosted-sign-in theme — it OVERRIDES the
 * environment default. Editing/preview is client-side (shared <x-theme-editor>); this
 * component seeds state and persists. The payload is re-sanitized through the typed
 * {@see Appearance} on save, so a malformed body degrades to the default, never errors.
 */
new #[Layout('components.layouts.app', ['title' => 'Appearance'])] class extends Component
{
    /** @var array<string, mixed> */
    public array $appearance = [];

    public function mount(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);

        $org = app(CurrentUser::class)->organization();
        $settings = is_array($org?->settings) ? $org->settings : [];

        $this->appearance = Appearance::fromSettings($settings)->toArray();
        $this->appearance['logo'] = is_string($settings['brand_logo_url'] ?? null) ? $settings['brand_logo_url'] : '';
        $this->appearance['name'] = $org?->name ?? '';
    }

    /**
     * @param  array<string, mixed>  $theme
     */
    public function save(array $theme, Organizations $organizations): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);

        $appearance = Appearance::fromArray($theme);
        $logo = self::normalizeLogo($theme['logo'] ?? null);

        $orgId = app(CurrentUser::class)->organizationId();
        if ($orgId === null) {
            return;
        }

        $organizations->updateSettings($orgId, [
            'appearance' => $appearance->toArray(),
            'brand_color' => $appearance->light->primary,
            'brand_logo_url' => $logo,
        ]);

        $this->appearance = $appearance->toArray();
        $this->appearance['logo'] = $logo ?? '';
        $this->appearance['name'] = app(CurrentUser::class)->organization()?->name ?? '';

        $this->dispatch('toast', message: 'Appearance saved.');
    }

    private static function normalizeLogo(mixed $value): ?string
    {
        $logo = is_string($value) ? trim($value) : '';

        return $logo !== '' && filter_var($logo, FILTER_VALIDATE_URL) !== false && str_starts_with($logo, 'https://')
            ? $logo
            : null;
    }
}; ?>

<x-theme-editor :appearance="$appearance" scope="organization"
    title="Appearance"
    subtitle="Your organization's sign-in theme — it overrides the environment default. Changes preview live and apply to your organization's hosted sign-in." />
