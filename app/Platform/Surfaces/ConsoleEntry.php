<?php

declare(strict_types=1);

namespace App\Platform\Surfaces;

use App\Http\Middleware\EnterConsoleThroughStart;

/**
 * THE CONSOLE'S FRONT DOOR, AS THE LOGIN HOST NAMES IT.
 *
 * In login-host mode the console is a relying party of the login host with a session of
 * its own. That session belongs to ONE account: the one that was active on the login host
 * when the console last asked. So whenever the login host changes who is active (a
 * sign-in, an added account, a switch) or where they stand (an accepted invitation, a
 * personal organization), sending the browser straight to a console page would show the
 * console's previous answer.
 *
 * `/auth/start` on the app host is where the console asks again: it runs the
 * authorization flow against the login host and lands on `return_to`, a path on the app
 * host. Every step from the login host into the console goes through it
 * ({@see EnterConsoleThroughStart}).
 */
final readonly class ConsoleEntry
{
    /** The console's relying-party start path, on the app host. */
    public const string PATH = '/auth/start';

    /** The query parameter that names the console path to land on. */
    public const string RETURN = 'return_to';

    public function __construct(private Surfaces $surfaces) {}

    /**
     * The start URL that lands on $path (a path on the app host, query string included).
     * Anything that is not a plain absolute path lands on the console's own default.
     */
    public function url(?string $path = null): string
    {
        return $this->surfaces->consoleUrl(self::PATH, $this->isPath($path) ? [self::RETURN => $path] : []);
    }

    /**
     * The start URL for an absolute URL on the app host, or null when $url is not one (or
     * already is the start, or its callback).
     */
    public function through(string $url): ?string
    {
        $origin = $this->surfaces->consoleOrigin();

        if ($origin === null || ! ($url === $origin || str_starts_with($url, $origin.'/') || str_starts_with($url, $origin.'?'))) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = $path === '' ? '/' : $path;

        if ($path === self::PATH || str_starts_with($path, '/auth/')) {
            return null;
        }

        $query = parse_url($url, PHP_URL_QUERY);

        return $this->url($path === '/' && ! is_string($query) ? null : $path.(is_string($query) && $query !== '' ? '?'.$query : ''));
    }

    private function isPath(?string $path): bool
    {
        return $path !== null
            && str_starts_with($path, '/')
            && ! str_starts_with($path, '//')
            && ! str_contains($path, '\\')
            && preg_match('/[\x00-\x20\x7f]/', $path) !== 1;
    }
}
