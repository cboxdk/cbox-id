<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Support;

use Cbox\Console\Kit\Branding\Branding;

/**
 * Validates and normalizes a tenant palette, then maps it to the CSS custom-property
 * names Cbox ID's stylesheet reads (`--primary`, `--accent`, `--ring`, `--foreground`,
 * `--background`). Only a fixed, safe subset of tokens is brandable, and only two
 * colour syntaxes are accepted — hex (`#0a2540`) and oklch (`oklch(0.45 0.16 258)`).
 * Anything else (named colours, `rgb()` with a trailing `;`, `url(...)`, an injected
 * declaration) is REJECTED and dropped: deny-by-default, so nothing unvalidated can
 * reach the shell's `<style>` tag. This is the plugin-side gate; the console-kit
 * {@see Branding} VO revalidates as defence in depth.
 */
class PaletteTokens
{
    /** The brandable tokens, in the order they are emitted. Keys map to `--<key>`. */
    public const TOKENS = ['primary', 'accent', 'ring', 'foreground', 'background'];

    /**
     * Map a raw palette (`token => colour`) to validated `--token => colour` pairs,
     * keeping only known tokens with a valid, normalized hex/oklch value.
     *
     * @param  array<array-key, mixed>  $palette
     * @return array<string, string>
     */
    public static function normalize(array $palette): array
    {
        $tokens = [];

        foreach (self::TOKENS as $key) {
            $value = $palette[$key] ?? null;

            if (! is_string($value)) {
                continue;
            }

            $normalized = self::normalizeColor($value);

            if ($normalized !== null) {
                $tokens['--'.$key] = $normalized;
            }
        }

        return $tokens;
    }

    /** Whether a value is an accepted colour (hex or oklch). */
    public static function isValidColor(string $value): bool
    {
        return self::normalizeColor($value) !== null;
    }

    /** Normalize an accepted colour to a canonical form, or null when it is not one. */
    public static function normalizeColor(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) === 1) {
            return strtolower($value);
        }

        // oklch(L C H) or oklch(L C H / A); L may carry a %, A a % — numbers only.
        if (preg_match('/^oklch\(\s*[0-9.]+%?\s+[0-9.]+\s+[0-9.]+(?:\s*\/\s*[0-9.]+%?)?\s*\)$/i', $value) === 1) {
            $collapsed = preg_replace('/\s+/', ' ', $value);

            return $collapsed === null ? null : strtolower(trim($collapsed));
        }

        return null;
    }
}
