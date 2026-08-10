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
        //
        // THE WHOLE SCALE, not just `--radius`. The stylesheet dresses cards from
        // `--radius`, buttons and inputs from `--radius-md`, badges and chips from
        // `--radius-sm`; overriding the first alone squared the cards and left everything
        // else rounded, so the corner control read as broken. See ThemeRadius::scale().
        $radius = $appearance->radius->scale();

        $root = $light
            .'--radius:'.$radius['radius'].';'
            .'--radius-md:'.$radius['md'].';'
            .'--radius-sm:'.$radius['sm'].';'
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
            // The same brand colour, walked along its own hue until it is legible as
            // TEXT on the tenant's background. --accent is picked to look right as a
            // button fill; used as a link or an icon colour it can sit near 3:1, which
            // fails AA for body text. Without this the token fell through to the
            // platform's default blue on every white-labeled page — readable, but not
            // the customer's colour, which is worse than the contrast bug it fixed.
            '--accent-strong:'.Color::readableOn($p, $bg).';',
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

            // Ground-derived tokens the branded pages actually reach. Omitting them was
            // not cosmetic: `.auth-shell` uses --canvas as its base surface, so every
            // white-labeled sign-in page painted the tenant's form onto the PLATFORM's
            // warm beige — and because --foreground IS emitted, a tenant whose light-mode
            // background is dark got their near-white text on our beige at about 1.02:1.
            // Their end users could not read the form they were being asked to sign in on.
            //
            // A stronger mix than --border because these are the edges a person has to
            // see to know where a control is: 26% for an input's border, 34% for the
            // separators that carry structure.
            "--canvas:color-mix(in srgb,{$fg} 4%,{$bg});",
            "--control-border:color-mix(in srgb,{$fg} 26%,{$bg});",
            "--border-strong:color-mix(in srgb,{$fg} 34%,{$bg});",
            "--popover:{$bg};",
            "--input:color-mix(in srgb,{$fg} 22%,{$bg});",
        ]);
    }
}
