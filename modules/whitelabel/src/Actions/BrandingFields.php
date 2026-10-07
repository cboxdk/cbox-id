<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Actions;

use App\Platform\Actions\Input\Field;
use Cbox\Id\Whitelabel\Models\BrandProfile;
use Cbox\Id\Whitelabel\Support\PaletteTokens;

/**
 * A white-label brand profile as the management API takes and returns it. A helper, not an
 * action.
 *
 * The two images are not input here. They are uploads — a PNG, a favicon — and the asset
 * store keeps them on this application's own origin, which is exactly why they are checked
 * as images by the console's upload form and never accepted as a URL somebody names (a
 * remote logo would be a beacon on every branded page). The API reads where they are.
 */
final class BrandingFields
{
    public static function palette(): Field
    {
        return Field::object('palette', array_map(
            static fn (string $token): Field => Field::string($token)->nullable()->max(100),
            PaletteTokens::TOKENS,
        ))->describe('Colours as hex (#0a2540) or oklch(…), by token: '.implode(', ', PaletteTokens::TOKENS).'. Sent, it is the complete palette; a token left out or blank is unset.');
    }

    /**
     * One profile — `WhitelabelBranding` in the spec. A missing profile is every field
     * empty: "not branded", which differs from branded-then-cleared only in that no row
     * exists yet.
     *
     * @return array<string, mixed>
     */
    public static function present(?string $organizationId, ?BrandProfile $profile): array
    {
        $palette = [];

        foreach (PaletteTokens::TOKENS as $token) {
            $palette[$token] = $profile?->palette->get($token);
        }

        return [
            'organization_id' => $organizationId,
            'palette' => $palette,
            'app_name' => $profile?->app_name,
            'email_from_name' => $profile?->email_from_name,
            'email_template' => $profile?->email_templates->get('welcome'),
            'logo_url' => $profile?->logo_url,
            'favicon_url' => $profile?->favicon_url,
        ];
    }
}
