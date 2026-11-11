<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Controllers\Console\PlatformAdminMovedController;

/**
 * Where platform admin lives: `/admin/…` on the platform root's host, for operators only.
 *
 * It was `/admin/platform/…` while `/admin` on the root host was the root environment's
 * console. That console moved to `/admin/environment/…`, so `/admin` itself is the
 * operators' now. The old `/admin/platform/…` addresses answer with a counted redirect
 * ({@see PlatformAdminMovedController}) until they have seen
 * no traffic for seven days.
 */
final class OperatorPaths
{
    /** The first path segment of every operator page under `/admin`. */
    public const SEGMENTS = ['organizations', 'environments', 'operators', 'usage', 'queues', 'search', 'interface-language'];

    /** Whether $path (`/admin/…`) is platform admin rather than an old environment-console address. */
    public static function owns(string $path): bool
    {
        if ($path === '/admin' || $path === '/admin/') {
            return true;
        }

        if (! str_starts_with($path, '/admin/')) {
            return false;
        }

        $segment = explode('/', substr($path, strlen('/admin/')), 2)[0];

        return in_array($segment, self::SEGMENTS, true);
    }

    /**
     * Where an old `/admin/platform/{path}` page is now, as a path under `/admin`.
     *
     * `workspaces` became `organizations` (a workspace is an organization of the platform
     * root). The pre-workspace `organizations` list was always the root environment's
     * organization tree, which lives under `environments/root/organizations`.
     */
    public static function fromPlatformPath(string $path): string
    {
        $path = trim($path, '/');

        $moved = match (true) {
            $path === 'workspaces', str_starts_with($path, 'workspaces/') => 'organizations'.substr($path, strlen('workspaces')),
            $path === 'organizations', str_starts_with($path, 'organizations/') => 'environments/root/'.$path,
            default => $path,
        };

        return '/admin'.($moved === '' ? '' : '/'.$moved);
    }
}
