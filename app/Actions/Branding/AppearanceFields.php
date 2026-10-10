<?php

declare(strict_types=1);

namespace App\Actions\Branding;

use App\Platform\Actions\Input\Field;
use App\Platform\Appearance\Appearance;
use App\Platform\Appearance\BrandImage;
use App\Platform\Appearance\BrandImages;
use App\Platform\Appearance\BrandImageUpload;
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

    /**
     * One uploaded image, as the API takes it: a base64 `data:` URI, null to remove it, or
     * left out to keep it. A URL is refused with the reason ({@see BrandImageUpload}).
     */
    public static function image(BrandImage $kind): Field
    {
        // Base64 is four characters per three bytes, plus the `data:…;base64,` prefix.
        $max = (int) ceil($kind->maxBytes() / 3) * 4 + 100;

        return Field::string($kind->value)->nullable()->max($max)->describe(
            'The '.$kind->label().' as a base64 data: URI (data:image/png;base64,…): '.$kind->formats()
            .', at most '.($kind->maxBytes() / 1024).' KB and '.$kind->maxDimension().'px on the longest side. '
            .'SVG and remote URLs are refused. Null removes it; left out keeps it.',
        );
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
            // This application's own URL for each uploaded image — never a remote one.
            'logo' => app(BrandImages::class)->absoluteUrl(BrandImage::Logo, $organizationId),
            'favicon' => app(BrandImages::class)->absoluteUrl(BrandImage::Favicon, $organizationId),
            // A remote logo URL saved before uploads replaced it. Kept in settings, never
            // fetched and never drawn; true until a logo is uploaded at this altitude.
            'remote_logo_ignored' => self::remoteLogoIgnored($settings),
        ];
    }

    /**
     * Whether this altitude still carries a legacy `brand_logo_url` that the hosted pages
     * no longer draw — the console's cue to ask for an upload.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function remoteLogoIgnored(array $settings): bool
    {
        $legacy = $settings['brand_logo_url'] ?? null;

        return is_string($legacy) && $legacy !== '';
    }
}
