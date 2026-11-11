<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\OAuth\PlatformRealmClients;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE SERVING HALF OF {@see PlatformRealmClients}: `/oauth/*` does not answer for an
 * organization's client in the platform's own realm.
 *
 * GLOBAL, and asked of the CLIENT rather than of the host or the resolved environment. The
 * host gates (`plane:*`, the surface fence) decide which hosts are an issuer at all, and
 * they change as the hosts do; this holds whatever they decide, including on the day the
 * platform realm's sign-in host becomes an issuer for every client it holds. It runs before
 * the environment is resolved, so it names the realm's environment itself.
 *
 * The client is read from wherever the request names it: `client_id`, HTTP Basic, or the
 * `sub` of a `client_assertion` (read unverified, which is safe for a rule that can only
 * refuse). The steps after `/oauth/authorize` (consent, the organization picker) name no
 * client; they act on a pending authorization that only an admitted `/oauth/authorize`
 * writes.
 *
 * 404, as the plane gates answer for a surface that is not there.
 */
final class RefuseCustomerClientsInPlatformRealm
{
    public function __construct(private readonly PlatformRealmClients $realm) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('oauth/*') || ! $this->realm->enabled()) {
            return $next($request);
        }

        foreach ($this->clientIds($request) as $clientId) {
            abort_if($this->realm->refusesToServe($clientId), 404);
        }

        return $next($request);
    }

    /** @return list<string> */
    private function clientIds(Request $request): array
    {
        $named = [$request->input('client_id'), $request->getUser()];

        $assertion = $request->input('client_assertion');

        if (is_string($assertion) && substr_count($assertion, '.') === 2) {
            $payload = base64_decode(strtr(explode('.', $assertion)[1], '-_', '+/'), true);
            $claims = $payload === false ? null : json_decode($payload, true);

            if (is_array($claims)) {
                $named[] = $claims['sub'] ?? null;
                $named[] = $claims['iss'] ?? null;
            }
        }

        return array_values(array_unique(array_filter(
            $named,
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        )));
    }
}
