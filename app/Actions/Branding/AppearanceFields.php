<?php

declare(strict_types=1);

namespace App\Actions\Branding;

use App\Platform\Actions\Input\Field;
use App\Platform\Appearance\Appearance;
use App\Platform\Appearance\ThemeFont;
use App\Platform\Appearance\ThemePresets;
use App\Platform\Appearance\ThemeRadius;

/**
 * The hosted sign-in theme as the management API takes and returns it. A helper, not an
 * action.
 *
 * The VALUES are not validated here, deliberately: {@see Appearance::fromArray()} sanitizes
 * every one on the way in — a colour that is not a six-digit hex becomes the preset's, an
 * unknown radius the default — and restating that here would be a second, drifting copy of
 * the sanitizer that decides what reaches a public `<style>` block. Only the SHAPE is.
 */
final class AppearanceFields
{
    public static function theme(): Field
    {
        $mode = static fn (string $name): Field => Field::object($name, [
            Field::string('primary')->max(32),
            Field::string('background')->max(32),
            Field::string('foreground')->max(32),
            Field::string('muted')->max(32),
        ])->describe('Colours as #rrggbb. One left out takes the preset\'s.');

        return Field::object('theme', [
            Field::string('preset')->max(64)->describe('A preset id: '.implode(', ', array_keys(ThemePresets::all())).'.'),
            Field::string('radius')->max(32)->describe('Corner radius: '.implode(', ', ThemeRadius::values()).'.'),
            Field::string('font')->max(32)->describe('Type family: '.implode(', ', array_keys(ThemeFont::stacks())).'.'),
            $mode('light'),
            $mode('dark'),
        ])->describe('The theme. Both modes must be readable: text needs 4.5:1 against the background, muted text 3:1.');
    }

    /** HTTPS or nothing: it is rendered into an `<img src>` on an unauthenticated page. */
    public static function secureLogo(string $logo): bool
    {
        return filter_var($logo, FILTER_VALIDATE_URL) !== false && str_starts_with($logo, 'https://');
    }

    /**
     * The theme at one altitude — `Appearance` in the spec.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function present(?string $organizationId, array $settings): array
    {
        return [
            'organization_id' => $organizationId,
            // Whether this altitude has a theme of its own. An organization without one
            // shows the environment default; an environment without one, the platform's.
            'customized' => Appearance::isCustomized($settings),
            'theme' => Appearance::fromSettings($settings)->toArray(),
            'logo' => is_string($settings['brand_logo_url'] ?? null) ? $settings['brand_logo_url'] : null,
        ];
    }
}
