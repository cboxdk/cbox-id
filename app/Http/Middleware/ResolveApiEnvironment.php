<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Api\CredentialEnvironment;
use App\Platform\Api\ManagementAudience;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Dpop\DpopResourceGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE ENVIRONMENT OF AN API-HOST REQUEST, FROM ITS CREDENTIAL — never from the host, which
 * is the same `api.cboxid.com` for everybody. The first that applies:
 *
 *  (a) a `cbid_env_` key: the environment its row belongs to;
 *  (b) an access token: the environment whose issuer `iss` names exactly, looked up before
 *      the token is trusted and then verified against that environment's keys by the door
 *      that admits it — never against "any" key (plan R3);
 *  (c) an organization key, or a platform-root person's token naming an environment in
 *      `Cbox-Environment`: no environment here; the organization plane needs none, and the
 *      root person's door binds the named one itself, as it does on the root host;
 *  (d) no credential: the organization, account and admin planes, or a public document.
 *
 * A credential that resolves to nothing pins nothing, so the door behind it refuses it as
 * it refuses any unknown credential; a key or token of another environment simply finds
 * none of this one's records (404). Which plane the credential speaks for is left on the
 * request for the routes that answer for two ({@see self::PLANE}).
 */
final readonly class ResolveApiEnvironment
{
    /** The request attribute naming the plane the credential speaks for. */
    public const string PLANE = 'cbox.api.plane';

    public function __construct(
        private CredentialEnvironment $credentials,
        private EnvironmentContext $context,
        private DpopResourceGuard $dpop,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $this->dpop->bearer($request);
        $kind = CredentialEnvironment::kind($bearer);

        if ($kind === 'token') {
            $claims = CredentialEnvironment::claims((string) $bearer) ?? [];

            if (ManagementAudience::retired($claims)) {
                return ManagementAudience::retiredChallenge($claims);
            }
        }

        $environment = $this->credentials->of($bearer);

        if ($environment !== null) {
            $this->context->set($environment);
        } elseif ($kind === 'environment_key') {
            // A key whose environment cannot serve (suspended, its organization suspended,
            // gone) acts in none: no environment at all, so every
            // environment-owned lookup behind it finds nothing, rather than whatever
            // environment the context last held.
            $this->context->set(null);
        }

        $request->attributes->set(self::PLANE, match (true) {
            $kind === 'organization_key' => 'organization',
            $kind === 'environment_key' => 'environment',
            $kind === 'token' && $this->credentials->isRoot($environment) => $request->headers->has(AuthenticateEnvironmentApi::ENVIRONMENT_HEADER) ? 'environment' : 'organization',
            $kind === 'token' => 'environment',
            default => 'none',
        });

        return $next($request);
    }
}
