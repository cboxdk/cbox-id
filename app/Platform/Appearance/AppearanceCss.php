<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

use Illuminate\Support\HtmlString;

/**
 * Turns a validated {@see Appearance} into the `<style>` block injected into the
 * hosted sign-in `<head>`. From four anchor colours per mode it derives a COHERENT
 * token set — accent + soft/edge/ring, an auto-contrast accent foreground, and
 * background-mixed border/secondary/faint — so the whole surface re-themes together,
 * not just the button.
 *
 * The cascade mirrors app.css exactly: light on :root, dark under both the
 * prefers-color-scheme media query and an explicit [data-theme='dark'], so the
 * page's own light/dark toggle keeps working over the custom theme.
 *
 * Safe by construction: every value originates from {@see Appearance} (hex-clamped,
 * allow-listed radius/font), never raw user input, so this never injects arbitrary
 * CSS into a public page.
 */
final class AppearanceCss
{
    public static function render(Appearance $appearance): HtmlString
    {
        $light = self::modeVars($appearance->light);
        $dark = self::modeVars($appearance->dark);

        // Radius + font are mode-independent — declared once on :root.
        $root = $light
            .'--radius:'.$appearance->radius->value.';'
            .'--font-sans:'.$appearance->fontStack().';';

        $css = ":root{{$root}}"
            ."@media(prefers-color-scheme:dark){:root:not([data-theme='light']){{$dark}}}"
            .":root[data-theme='dark']{{$dark}}";

        return new HtmlString("<style id=\"cbox-appearance\">{$css}</style>");
    }

    /**
     * The coherent token override for one mode.
     */
    private static function modeVars(ThemeMode $m): string
    {
        $p = $m->primary;
        $bg = $m->background;
        $fg = $m->foreground;
        $mu = $m->muted;
        $on = Color::readableForeground($p);

        return implode('', [
            // --primary drives the primary CTA (.btn-primary background); without
            // overriding it, a white-labeled "Sign in" button rendered the platform
            // deep-blue --primary at rest and only flipped to the tenant colour on
            // :hover (which uses --accent) — a jarring rest→hover colour change and a
            // broken brand promise. Map it (and its auto-contrast foreground) to the
            // tenant primary so the button IS the chosen colour.
            "--primary:{$p};",
            "--primary-foreground:{$on};",
            "--accent:{$p};",
            "--ring:{$p};",
            "--accent-foreground:{$on};",
            "--accent-soft:color-mix(in srgb,{$p} 12%,transparent);",
            "--accent-edge:color-mix(in srgb,{$p} 32%,transparent);",
            "--background:{$bg};",
            "--foreground:{$fg};",
            "--card:{$bg};",
            "--card-foreground:{$fg};",
            "--secondary:color-mix(in srgb,{$fg} 6%,{$bg});",
            "--secondary-foreground:{$fg};",
            "--muted-foreground:{$mu};",
            "--faint:color-mix(in srgb,{$mu} 65%,{$bg});",
            "--border:color-mix(in srgb,{$fg} 14%,{$bg});",
            "--input:color-mix(in srgb,{$fg} 22%,{$bg});",
        ]);
    }
}
