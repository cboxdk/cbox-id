<?php

declare(strict_types=1);

namespace App\Platform\Api;

use App\Mcp\McpProtectedResources;
use App\Mcp\TransitionalProtectedResource;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\OAuthServer\ValueObjects\ProtectedResource;
use Illuminate\Http\JsonResponse;

/**
 * The audiences a token for Cbox ID's management planes may carry (plan §2.5).
 *
 *  - `https://api.cboxid.com`: the API host's `/v1`;
 *  - `https://app.cboxid.com/mcp`: the one MCP server, on the application host;
 *  - `{issuer}/mcp`: the legacy one, an environment's own MCP server, accepted on every
 *    door until the contract step turns `cbox-id.api_transition.legacy_mcp_audience` off.
 *
 * The audience only says "this token is for the management plane". WHICH environment it
 * acts in is the issuer's to say ({@see CredentialEnvironment}), so a token for the API host
 * from environment A acts in A and nowhere else. One token works at both doors, as before.
 */
final class ManagementAudience
{
    /** The API host's resource identifier, or null without an API host. */
    public static function api(): ?string
    {
        $host = ApiHost::name();

        return $host !== null ? 'https://'.$host : null;
    }

    /** The application host's MCP server, or null on a single-host deployment. */
    public static function appMcp(): ?string
    {
        $host = ApiHost::appName();

        return $host !== null ? 'https://'.$host.McpProtectedResources::PATH : null;
    }

    /** @return list<string> the current identifiers, without the legacy one */
    public static function current(): array
    {
        return array_values(array_filter([self::api(), self::appMcp()], static fn (?string $id): bool => $id !== null));
    }

    public static function legacyAccepted(): bool
    {
        return config('cbox-id.api_transition.legacy_mcp_audience', true) !== false;
    }

    /**
     * Whether the token names a management-plane audience: one of the current ones, or —
     * while the transition lasts — the issuer's own `/mcp` ($legacy). An absent `aud` is
     * never enough.
     */
    public static function accepts(Introspection $token, string $legacy): bool
    {
        $aud = $token->claims['aud'] ?? null;

        if ($aud === null || $aud === '' || $aud === []) {
            return false;
        }

        foreach (self::current() as $identifier) {
            if ($token->isAudience($identifier)) {
                return true;
            }
        }

        return self::legacyAccepted() && $token->isAudience($legacy);
    }

    /**
     * Whether these claims name ONLY a retired audience (an issuer's own `/mcp`) once the
     * transition is over — the case the documented 401 is for.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function retired(array $claims): bool
    {
        if (self::legacyAccepted()) {
            return false;
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_string($aud) ? [$aud] : (is_array($aud) ? array_filter($aud, 'is_string') : []);
        $issuer = is_string($claims['iss'] ?? null) ? rtrim($claims['iss'], '/') : null;

        if ($audiences === [] || $issuer === null || array_intersect($audiences, self::current()) !== []) {
            return false;
        }

        return in_array($issuer.McpProtectedResources::PATH, $audiences, true);
    }

    /**
     * The 401 for a retired audience: what was refused and what to ask for instead, with
     * the RFC 9728 pointer a client follows to find out more.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function retiredChallenge(array $claims): JsonResponse
    {
        $issuer = is_string($claims['iss'] ?? null) ? rtrim($claims['iss'], '/') : '';
        $resource = self::api() ?? 'https://api.cboxid.com';
        $description = "audience {$issuer}/mcp retired; request resource {$resource}";

        return new JsonResponse(
            ['error' => 'invalid_token', 'message' => $description, 'error_description' => $description],
            401,
            ['WWW-Authenticate' => 'Bearer error="invalid_token", error_description="'.$description.'", resource_metadata="'.$resource.ProtectedResource::WELL_KNOWN.'"'],
        );
    }

    /**
     * The current resources, each the template's twin under its own identifier — the
     * same scopes, the same openness to self-registered clients.
     *
     * @return list<ProtectedResource>
     */
    public static function resources(ProtectedResource $template): array
    {
        $resources = [];

        foreach (self::current() as $identifier) {
            if ($identifier === $template->identifier) {
                continue;
            }

            $resources[] = new TransitionalProtectedResource(
                identifier: $identifier,
                scopes: $template->scopes,
                dynamicClients: $template->dynamicClients,
                name: $identifier === self::api() ? 'Cbox ID API' : $template->name,
            );
        }

        return $resources;
    }
}
