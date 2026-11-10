<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The v2 addresses of a person's own settings: `/account/…` and `/accounts/…` are
 * `/settings/…` now, on every host. ID is live, and these paths are in bookmarks, in
 * e-mails and in the SDKs' deep link (`/account/api-keys?client_id=…&return_to=…`), so
 * they keep answering, query string and all. A read is a 301; a write is a 308, so a form
 * an old tab still has open posts its body to the new address. Removed once they have
 * seen no traffic for seven days.
 */
final class LegacySettingsPathController
{
    /** v2 prefix => new prefix. Longest prefix wins. */
    public const PREFIXES = [
        '/account' => '/settings',
        '/accounts' => '/settings/accounts',
    ];

    public function __invoke(Request $request): RedirectResponse
    {
        $query = $request->getQueryString();

        return redirect()->to(
            url(self::rewrite('/'.ltrim($request->path(), '/'))).($query === null || $query === '' ? '' : '?'.$query),
            $request->isMethod('GET') || $request->isMethod('HEAD') ? 301 : 308,
        );
    }

    public static function rewrite(string $path): string
    {
        $best = null;

        foreach (array_keys(self::PREFIXES) as $prefix) {
            if (($path === $prefix || str_starts_with($path, $prefix.'/')) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best === null ? $path : self::PREFIXES[$best].substr($path, strlen($best));
    }
}
