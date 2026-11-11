<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\Organizations\LastUsedOrganization;
use App\Platform\Organizations\OrganizationDirectory;
use App\Platform\PlaneResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The v2 addresses of the organization console, kept answering while ID is live.
 *
 * Each answers with the same page under `/orgs/{org}`, in the organization this browser
 * last used (else the person's first). A read is a 302, because where it lands depends
 * on who asks; a write is a 307, so a form left open in an old tab still posts its body
 * to the new address. Removed once these paths have seen no traffic for seven days.
 *
 * Only on the app host: on a tenant host these pages never existed, and the paths stay
 * the 404 they were.
 */
final class LegacyOrganizationPathController
{
    /** v2 path prefix => the same page under `/orgs/{org}`. Longest prefix wins. */
    public const PREFIXES = [
        '/projects' => '/projects',
        '/team' => '/settings/members',
        '/keys' => '/settings/api-keys',
        '/workspace-settings' => '/settings',
        '/environment-domains' => '/environment-domains',
        '/billing' => '/settings/billing',
    ];

    /** Sub-paths that were renamed inside a prefix. */
    private const RENAMED = [
        '/keys/workspace' => '/settings/api-keys/organization',
    ];

    public function __invoke(Request $request, OrganizationDirectory $directory, CurrentUser $me, PlaneResolver $planes): RedirectResponse
    {
        abort_if($planes->onCustomerEnvironment(), 404);

        $entry = LastUsedOrganization::resolve($request, $directory, $me->id(), $me->organizationId());

        // In no organization: where `/` sends this person (an operator's platform, the
        // picker for everybody else), never a page that names an organization they lack.
        if ($entry === null) {
            return redirect()->route(app(ConsoleScope::class)->isPlatformOperator() ? 'platform.environments' : 'dashboard');
        }

        $path = '/'.ltrim($request->path(), '/');
        $query = $request->getQueryString();
        $target = '/orgs/'.rawurlencode($entry->slug).self::rewrite($path);

        return redirect()->to(
            url($target).($query === null || $query === '' ? '' : '?'.$query),
            $request->isMethod('GET') || $request->isMethod('HEAD') ? 302 : 307,
        );
    }

    /** The path under `/orgs/{org}` that a v2 path moved to. */
    public static function rewrite(string $path): string
    {
        foreach ([self::RENAMED, self::PREFIXES] as $table) {
            $best = null;
            $target = '';

            foreach ($table as $prefix => $to) {
                if (($path === $prefix || str_starts_with($path, $prefix.'/')) && ($best === null || strlen($prefix) > strlen($best))) {
                    $best = $prefix;
                    $target = $to;
                }
            }

            if ($best !== null) {
                return $target.substr($path, strlen($best));
            }
        }

        return '';
    }
}
