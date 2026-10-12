<?php

declare(strict_types=1);

namespace App\Platform\AccountLinking;

use App\Platform\Social\ProviderSetupSteps;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;

/**
 * MICROSOFT ENTRA ID'S WAY OF SAYING "THIS ADDRESS IS VERIFIED" — the optional `xms_edov`
 * claim, because Entra never sends `email_verified`.
 *
 * Entra's `email` claim is whatever is in the user's mail attribute, and an administrator
 * of ANY Entra tenant can type any address into it — which is exactly why the link step
 * refused every Entra sign-in: without a verification claim, "Link your Microsoft account
 * to sn@cbox.dk?" rests on a string somebody typed. Microsoft's documented answer is
 * `xms_edov` ("email domain owner verified"): true when the address's domain is one the
 * user's own tenant has verified ownership of. It is an OPTIONAL claim, so it is only in
 * the token when the app registration asks for it — Token configuration › Add optional
 * claim › ID › `xms_edov` (with `email`, which it depends on). The Microsoft provider's
 * setup steps in the console say so ({@see ProviderSetupSteps}).
 *
 * Read STRICTLY: the JSON boolean `true`, as Microsoft documents it. Absent, false, a
 * string — no. A tenant that did not add the claim keeps getting the existing refusal
 * sentence, which tells the person to connect Microsoft from My account instead.
 *
 * WHICH SIGN-INS ARE ENTRA's: a connection made from the catalogue's `microsoft` template
 * (environment-wide or an organization's), the operator's own Microsoft provider
 * (`social:microsoft`), or any OIDC connection whose token was issued by
 * `login.microsoftonline.com` — an organization that configured Entra as a generic OIDC
 * connection is still talking to Entra.
 */
final class EntraVerifiedEmail
{
    public const string CLAIM = 'xms_edov';

    private const string ISSUER_PREFIX = 'https://login.microsoftonline.com/';

    public static function isEntra(FederatedPrincipal $principal, ?Connection $connection): bool
    {
        if ($connection?->provider === 'microsoft' || $principal->provider === 'social:microsoft') {
            return true;
        }

        $issuer = $principal->raw['iss'] ?? null;

        return is_string($issuer) && str_starts_with($issuer, self::ISSUER_PREFIX);
    }

    /** Whether the token carried `xms_edov: true` — the address's domain is verified by its tenant. */
    public static function domainOwnerVerified(FederatedPrincipal $principal): bool
    {
        return ($principal->raw[self::CLAIM] ?? null) === true;
    }

    /** Both: an Entra sign-in whose token says the address's domain is its tenant's. */
    public static function for(FederatedPrincipal $principal, ?Connection $connection): bool
    {
        return self::isEntra($principal, $connection) && self::domainOwnerVerified($principal);
    }
}
