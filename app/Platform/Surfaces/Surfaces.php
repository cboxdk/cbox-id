<?php

declare(strict_types=1);

namespace App\Platform\Surfaces;

use App\Http\Middleware\FenceSurfaces;
use App\Http\Middleware\RedirectLegacyLoginHost;
use App\Platform\Api\ApiHost;
use App\Platform\PlaneResolver;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

/**
 * WHICH SURFACE A HOST IS, and the URLs that cross from one surface to another.
 *
 * LOGIN-HOST MODE is on when `cbox-id.hosts.login` (`CBOX_ID_LOGIN_HOST`) names a host. The
 * default environment is then issued from that host and from no other, and the console
 * moves to the app host (`cbox-id.tenancy.account_host`) as a relying party of it:
 *
 *  - {@see Surface::Login}: the login host. Hosted sign-in, sign-up and consent, `/oauth/*`,
 *    discovery and JWKS, the frontend API, a person's own security pages.
 *  - {@see Surface::Console}: the app host. The console and the operator plane.
 *  - {@see Surface::Api}: the API host.
 *  - {@see Surface::Tenant}: a host that resolves to an environment other than the default.
 *  - {@see Surface::Legacy}: a name the default environment used to be issued under
 *    (`cbox-id.hosts.legacy_login`). It only redirects; see {@see RedirectLegacyLoginHost}.
 *  - {@see Surface::Unmapped}: anything else. It issues for nobody.
 *
 * The surface is decided by an EXACT match on the host, never by the environment the
 * request resolved to: an unmapped host resolves to the default environment, and a console
 * path names a tenant environment on the app host, so the context can say "issuer" on two
 * hosts that are not one.
 *
 * SINGLE-HOST MODE is the login host left unset, and it is every install that has not
 * asked for the split. {@see current()} and {@see of()} answer null there, {@see admits()}
 * admits everything, and both URL builders stay on the host the request arrived on, so
 * nothing in this class changes what such an install serves.
 *
 * For other code: ask {@see split()} whether the mode is on, {@see current()} or
 * {@see is()} where it stands, and {@see loginUrl()} / {@see consoleUrl()} for a link to
 * the other surface. Which paths belong to which surface is {@see kindOf()}; a route that
 * moves to the login host is added to {@see ISSUER} or {@see ACCOUNT} here and nowhere else.
 */
final class Surfaces
{
    /**
     * Probes and metrics. Answered on every host, the unmapped ones too: a kubelet probes
     * the pod address, and each of these carries its own guard.
     *
     * @var list<string>
     */
    public const array INFRASTRUCTURE = [
        'up', 'health', 'health/*', 'internal/v1/*', 'telemetry/*', 'queue-metrics', 'queue-metrics/*',
    ];

    /**
     * Answered on every named surface: assets, sign-out of the session that surface holds,
     * the language picker, first run, the old `/api/v1` paths (which answer on every host
     * until they are retired) and the resource metadata an MCP client reads.
     *
     * @var list<string>
     */
    public const array SHARED = [
        '/', 'robots.txt', 'favicon.ico', 'build/*', 'storage/*', 'brand-assets/*', 'app-icons/*',
        'locale', 'logout', 'signed-out', 'first-run',
        'authenticator/privacy',
        'api/v1', 'api/v1/*',
        '.well-known/oauth-protected-resource', '.well-known/oauth-protected-resource/*',
    ];

    /**
     * The issuer. On the login host and on a tenant's host; absent on the app host.
     *
     * @var list<string>
     */
    public const array ISSUER = [
        // The hosted doors.
        'login', 'login/*', 'o/*/login', 'signup', 'start', 'start/*',
        'magic/*', 'forgot-password', 'reset-password', 'reset-password/*',
        'passkeys/login', 'passkeys/login/*',
        'auth/*/redirect', 'auth/*/callback',
        // The claimed-HTTPS redirect URI of the Authenticator's client (no route answers it:
        // the app is handed the URL by the system).
        'app/oauth/callback',
        'mfa', 'mfa/*',
        'invitations/*', 'invite/*', 'verify-email/*',
        // On to the app after a hold or a mailed link.
        'continue',
        // Inbound federation and the SAML IdP bindings.
        'sso/*',
        // The protocol surface.
        'oauth/*', 'device', 'device/*', 'scim/*',
        '.well-known/openid-configuration', '.well-known/jwks.json', '.well-known/oauth-authorization-server',
        '.well-known/cbox-authenticator', '.well-known/cbox-cli',
        // The embedded sign-in endpoints.
        'frontend/v1/*',
        // The admin portal an organization's IT administrator is sent a link to.
        'setup', 'setup/*',
    ];

    /**
     * A person's own pages: who they are, how they sign in, where they are signed in and
     * what can act as them. They act on the SSO session, so they live with it: on the login
     * host and on a tenant's host, absent on the app host. What belongs to an ORGANIZATION
     * (members, roles, its keys, its single sign-on) is the console's and is not here.
     *
     * `settings` is the page itself; `settings/organization` and `settings/branding` are
     * old console addresses and stay the console's, which is why this is not `settings/*`.
     *
     * @var list<string>
     */
    public const array ACCOUNT = [
        // Confirming it is you, and the password change a hold sends a person to.
        'sudo', 'sudo/*',
        'password/change',
        // The security page and each write on it.
        'settings',
        'settings/profile',
        'settings/password',
        'settings/two-factor', 'settings/two-factor/*',
        'settings/passkeys/*', 'passkeys/register', 'passkeys/register/*',
        'settings/social/*', 'settings/connect/*',
        'settings/sign-in-methods/*',
        'settings/email/*',
        // Where you are signed in and what can act as you.
        'settings/activity',
        'settings/sessions/*',
        'settings/applications/*',
        'settings/devices', 'settings/devices/*', 'authenticator/review',
        // The accounts this browser holds, and switching among them.
        'settings/accounts', 'settings/accounts/*',
        // A person's own credentials at the apps built here, and what apps may reach
        // through them. Apps deep-link to these on the issuer.
        'settings/api-keys', 'settings/api-keys/*',
        'settings/connected-services', 'settings/connected-services/*',
        'settings/services', 'settings/services/*',
        // Signed in, in no organization: an invitation or a personal organization.
        'organization-required', 'organization-required/*',
        // The v2 addresses of all of the above, and the connected-services callback
        // providers hold as a redirect URI.
        'account', 'account/*', 'accounts', 'accounts/*',
    ];

    /**
     * Pages people hold links to under a legacy name (bookmarks, mailed links, the
     * Authenticator app's "open my account") that are not on the issuer's lists above. A
     * legacy host sends them to the host that serves them now; every other console path
     * is absent there. The two `oauth/*` entries are the pages a browser opens, taken out
     * of {@see LEGACY_GONE}.
     *
     * @var list<string>
     */
    public const array LEGACY_PAGES = [
        'settings', 'settings/*', 'account', 'account/*', 'approvals', 'dashboard',
        'oauth/logout', 'oauth/device/qr',
    ];

    /**
     * What a program calls. A legacy host never redirects these, in any method: a client
     * that followed would carry its credential, or finish an authorization, at a host it
     * was not configured for, and one that did not would fail without being told why.
     *
     * @var list<string>
     */
    public const array LEGACY_GONE = [
        'oauth/*', 'api/v1', 'api/v1/*', 'v1', 'v1/*', 'mcp', 'mcp/*', 'scim/*', 'frontend/v1/*',
    ];

    public function __construct(private readonly EnvironmentResolver $resolver) {}

    /**
     * In login-host mode a pinned passkey origin (`cbox-id.webauthn.origin`) is the login
     * host's origin and no other. Called once at boot.
     *
     * A pinned relying-party id may be a parent domain (`example.com`), which every host
     * under it satisfies; the ORIGIN is what keeps a passkey ceremony on one host. Left
     * naming the old root or the app host, a passkey could be asserted from the console's
     * origin, which is the origin the split exists to keep credentials away from.
     */
    public static function pinPasskeyOrigin(): void
    {
        $login = config('cbox-id.hosts.login');
        $origin = config('cbox-id.webauthn.origin');

        if (! is_string($login) || trim($login) === '' || ! is_string($origin) || $origin === '') {
            return;
        }

        $login = mb_strtolower(trim($login));

        if (parse_url($origin, PHP_URL_HOST) === $login) {
            return;
        }

        $scheme = parse_url($origin, PHP_URL_SCHEME);
        $port = parse_url($origin, PHP_URL_PORT);

        config(['cbox-id.webauthn.origin' => (is_string($scheme) && $scheme !== '' ? $scheme : 'https').'://'.$login.(is_int($port) ? ':'.$port : '')]);
    }

    /** Whether login-host mode is on. */
    public function split(): bool
    {
        return $this->loginHost() !== null;
    }

    /** The login host, or null in single-host mode. */
    public function loginHost(): ?string
    {
        $host = config('cbox-id.hosts.login');

        return is_string($host) && trim($host) !== '' ? mb_strtolower(trim($host)) : null;
    }

    /**
     * The names the default environment used to be issued under, lower-cased. Empty in
     * single-host mode: there is no login host to send anybody to.
     *
     * @return list<string>
     */
    public function legacyLoginHosts(): array
    {
        $configured = config('cbox-id.hosts.legacy_login', []);

        if (! $this->split() || ! is_array($configured)) {
            return [];
        }

        $hosts = [];

        foreach ($configured as $host) {
            if (is_string($host) && trim($host) !== '') {
                $hosts[] = mb_strtolower(trim($host));
            }
        }

        return array_values(array_unique($hosts));
    }

    /** The app host the console is served on, or null when the deployment names none. */
    public function consoleHost(): ?string
    {
        return app(PlaneResolver::class)->consoleHost();
    }

    /**
     * The surface of the current request, or null in single-host mode.
     *
     * A host header that cannot be read is {@see Surface::Unmapped}.
     */
    public function current(?Request $request = null): ?Surface
    {
        if (! $this->split()) {
            return null;
        }

        $request ??= request();

        try {
            return $this->of($request->getHost());
        } catch (SuspiciousOperationException) {
            return Surface::Unmapped;
        }
    }

    /** Whether the current request is on one of the given surfaces. Never in single-host mode. */
    public function is(Surface ...$surfaces): bool
    {
        return in_array($this->current(), $surfaces, true);
    }

    /**
     * The surface a host is, or null in single-host mode.
     *
     * The platform's own hosts first and by exact match, so no environment's domain or slug
     * can answer for one of them.
     */
    public function of(string $host): ?Surface
    {
        $login = $this->loginHost();

        if ($login === null) {
            return null;
        }

        $host = mb_strtolower($host);

        if ($host === ApiHost::name()) {
            return Surface::Api;
        }

        if ($host === $login) {
            return Surface::Login;
        }

        if ($host === $this->consoleHost()) {
            return Surface::Console;
        }

        if ($this->isMarketingHost($host)) {
            return Surface::Marketing;
        }

        // Before the resolver: the old name may still resolve to an environment by slug
        // (the default one, after the flip), and must not answer as it.
        if (in_array($host, $this->legacyLoginHosts(), true)) {
            return Surface::Legacy;
        }

        $resolved = $this->resolver->resolveForHost($host)?->environmentKey();

        // A host that resolves to the default environment and is none of the hosts above is
        // not the default environment's host: it is a name somebody pointed at us.
        return $resolved !== null && $resolved !== $this->defaultEnvironmentKey()
            ? Surface::Tenant
            : Surface::Unmapped;
    }

    /** Which surfaces a request's path belongs to. */
    public function kindOf(Request $request): PathKind
    {
        return $this->kindOfPath($request->decodedPath());
    }

    /** The same question of a path this application serves (`settings/activity`, `/dashboard`). */
    public function kindOfPath(string $path): PathKind
    {
        $path = trim($path, '/');
        $path = $path === '' ? '/' : $path;

        return match (true) {
            self::matches(self::INFRASTRUCTURE, $path) => PathKind::Infrastructure,
            self::matches(self::SHARED, $path) => PathKind::Shared,
            self::matches(self::ISSUER, $path) => PathKind::Issuer,
            self::matches(self::ACCOUNT, $path) => PathKind::Account,
            default => PathKind::Console,
        };
    }

    /** @param list<string> $patterns */
    private static function matches(array $patterns, string $path): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern === '/' ? '/' : trim($pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether what is running acts in the default environment (or in none): the realm the
     * login host issues for. False while a console page or a job acts in a tenant's
     * environment, whose own sign-in pages are on its own host.
     */
    public function inDefaultEnvironment(): bool
    {
        $current = app(EnvironmentContext::class)->current()?->environmentKey();

        return $current === null || $current === $this->defaultEnvironmentKey();
    }

    /**
     * Whether this host serves this path at all ({@see FenceSurfaces}).
     *
     * A tenant's host is left to the plane gates on its routes, as before; the API and
     * marketing hosts have fences of their own.
     */
    public function admits(Request $request): bool
    {
        $surface = $this->current($request);

        if ($surface === null) {
            return true;
        }

        $kind = $this->kindOf($request);

        return match ($surface) {
            Surface::Api, Surface::Marketing, Surface::Tenant => true,
            // What a legacy host answers beyond the probes is RedirectLegacyLoginHost's,
            // which runs ahead of the fence; nothing else is served there.
            Surface::Unmapped, Surface::Legacy => $kind === PathKind::Infrastructure,
            Surface::Login => $kind !== PathKind::Console,
            Surface::Console => $kind !== PathKind::Issuer && $kind !== PathKind::Account,
        };
    }

    /**
     * An absolute URL on the login host: where a person signs in and manages their own
     * security. In single-host mode it is a URL on the host the request arrived on.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function loginUrl(string $path = '/', array $query = []): string
    {
        return $this->urlOn($this->loginHost(), $path, $query);
    }

    /**
     * An absolute URL on the app host: the console and the operator plane. Where the
     * deployment names no app host it is a URL on the host the request arrived on.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function consoleUrl(string $path = '/', array $query = []): string
    {
        return $this->urlOn($this->consoleHost(), $path, $query);
    }

    /**
     * The origin (`scheme://host[:port]`) of the login host, or null in single-host mode.
     */
    public function loginOrigin(): ?string
    {
        $host = $this->loginHost();

        return $host === null ? null : $this->origin($host);
    }

    /** The origin of the app host, or null when the deployment names none. */
    public function consoleOrigin(): ?string
    {
        $host = $this->consoleHost();

        return $host === null ? null : $this->origin($host);
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function urlOn(?string $host, string $path, array $query): string
    {
        $path = '/'.ltrim($path, '/');
        $suffix = $query === [] ? '' : '?'.http_build_query($query);

        if ($host === null) {
            return url($path).$suffix;
        }

        return $this->origin($host).($path === '/' && $suffix === '' ? '' : $path).$suffix;
    }

    /**
     * The scheme and port follow the request being served, so a local install on a port
     * links to the other surface on the same port; outside a request they follow `app.url`.
     */
    private function origin(string $host): string
    {
        if (! app()->runningInConsole() || app()->runningUnitTests()) {
            $request = request();
            $port = $request->getPort();
            $standard = $port === null || in_array((int) $port, [80, 443], true);

            return $request->getScheme().'://'.$host.($standard ? '' : ':'.$port);
        }

        $appUrl = config('app.url');
        $scheme = is_string($appUrl) ? parse_url($appUrl, PHP_URL_SCHEME) : null;

        return (is_string($scheme) && $scheme !== '' ? $scheme : 'https').'://'.$host;
    }

    private function isMarketingHost(string $host): bool
    {
        $marketing = config('marketing.host');

        return is_string($marketing) && $marketing !== ''
            && in_array($host, [mb_strtolower($marketing), 'www.'.mb_strtolower($marketing)], true);
    }

    /** Resolved as {@see PlaneResolver} resolves the platform root, so the two agree. */
    public function defaultEnvironmentKey(): ?string
    {
        $default = $this->resolver->defaultEnvironment()?->environmentKey();

        if ($default !== null) {
            return $default;
        }

        $configured = config('cbox-id.environments.default');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }
}
