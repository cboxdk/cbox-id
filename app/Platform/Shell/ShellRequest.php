<?php

declare(strict_types=1);

namespace App\Platform\Shell;

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Organizations\OrganizationLinks;
use App\Platform\PlaneResolver;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * What the shell's levels read off a request: who is asking, and which organization,
 * project and environment the URL names.
 *
 * The URL is the state. `BindOrganizationFromPath` and `ResolveEnvironmentFromPath` move
 * `{org}` and `{environmentPath}` into the URL defaults once they have checked them, so the
 * page's own route no longer carries them; a request the shell endpoints match by hand
 * (the switch's `from`) still does. Both are read, the checked one first.
 */
final class ShellRequest
{
    /** The person behind the request: the app-host session, else the environment console's admin. */
    public static function subjectId(): ?string
    {
        $user = app(CurrentUser::class);

        if ($user->check()) {
            $id = $user->id();

            return $id !== '' ? $id : null;
        }

        return app(EnvironmentAdminAuth::class)->subjectId();
    }

    /** `{org}`, as the path named it. */
    public static function organizationSlug(Request $request): ?string
    {
        $slug = URL::getDefaultParameters()['org'] ?? $request->route()?->parameter('org');

        if (is_string($slug) && $slug !== '') {
            return $slug;
        }

        return self::environmentPathParts($request)[0] ?? null;
    }

    /** The project slug an environment console path names. */
    public static function projectSlug(Request $request): ?string
    {
        return self::environmentPathParts($request)[1] ?? null;
    }

    /** `{environmentPath}`, as the path named it. */
    public static function environmentPath(Request $request): ?string
    {
        $path = URL::getDefaultParameters()[EnvironmentConsolePath::PARAMETER]
            ?? $request->route()?->parameter(EnvironmentConsolePath::PARAMETER);

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * [organization slug, project slug, environment slug] of a customer environment's
     * console path, or [] (the platform root's own console has none).
     *
     * @return list<string>
     */
    public static function environmentPathParts(Request $request): array
    {
        $path = self::environmentPath($request);

        if ($path === null || preg_match('#\Aorgs/([^/]+)/projects/([^/]+)/environments/([^/]+)\z#', $path, $parts) !== 1) {
            return [];
        }

        return [$parts[1], $parts[2], $parts[3]];
    }

    /**
     * A customer environment's own organization console (its IT admins, on its host): the
     * organization plane while the request stands in a customer's environment. Its chrome is
     * white-labelled. An environment console on the app host stands in a customer's
     * environment too, and is not this.
     */
    public static function onTenantConsole(): bool
    {
        return app(PlaneResolver::class)->onCustomerEnvironment()
            && app(ConsoleScope::class)->plane() === ConsolePlane::Organization;
    }

    /**
     * The organization console on the `cbox` environment's own host (cbox.cboxid.com): the
     * pages every person signed in to a Cbox app has, their account and their Cbox
     * organization's. It is Cbox's own environment, so it is not white-labelled: it renders
     * in the shared shell like every other Cbox app, with the Cbox app switcher and Help.
     */
    public static function onCboxConsole(): bool
    {
        if (! self::onTenantConsole()) {
            return false;
        }

        $cbox = app(OrganizationLinks::class)->cboxEnvironmentId();
        $here = app(EnvironmentContext::class)->current()?->environmentKey();

        return $cbox !== null && $here === $cbox;
    }

    /**
     * A customer environment's white-labelled console: a tenant console that is not
     * Cbox's own. It keeps its own chrome, because `CboxShell` (cbox-ui 0.11) always draws
     * the Cbox app switcher, Help and the Cbox wordmark.
     */
    public static function onWhiteLabelConsole(): bool
    {
        return self::onTenantConsole() && ! self::onCboxConsole();
    }

    /**
     * Platform admin: `/admin/*` and the platform root's own environment console. Those pages
     * act on the whole deployment, not on one organization, so they show no context levels.
     */
    public static function onPlatformAdmin(Request $request): bool
    {
        return $request->routeIs('platform.*') || self::environmentPath($request) === EnvironmentConsolePath::ROOT;
    }
}
