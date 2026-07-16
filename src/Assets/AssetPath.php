<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Assets;

/**
 * Maps a stored asset URL back to its disk-relative path (everything from the base
 * folder onward), so an asset can be removed when it is replaced. Best-effort: it
 * only returns a path that begins with the expected base folder, so an arbitrary
 * external URL can never resolve to a deletable path.
 */
final class AssetPath
{
    public static function fromUrl(?string $url, string $basePath): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = ltrim(rawurldecode($path), '/');
        $needle = trim($basePath, '/').'/';
        $position = strpos($path, $needle);

        if ($position === false) {
            return null;
        }

        $relative = substr($path, $position);

        // Refuse traversal; the path must stay within the base folder.
        return str_contains($relative, '..') ? null : $relative;
    }
}
