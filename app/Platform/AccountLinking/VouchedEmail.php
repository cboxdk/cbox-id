<?php

declare(strict_types=1);

namespace App\Platform\AccountLinking;

use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Federation\Support\OrganizationVouchedEmail;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;

/**
 * DID THE PROVIDER SAY THIS ADDRESS IS THE PERSON'S? The first gate on the link step.
 *
 * The step offers to attach a provider identity to an account that already uses the same
 * address. It asks the person to prove they control that account, so the provider's word
 * is not what makes the link safe — but it is what makes the OFFER make sense. A provider
 * that never checked the address (Discord carries whatever was typed, Entra issues
 * `email` without `email_verified`) has told us nothing about whose inbox it is, and
 * "Link your Discord to sn@cbox.dk?" on the strength of a string somebody typed into
 * Discord is a question we should not be asking. Those are refused with a sentence, and the
 * person can still connect the provider from My account, where they are already signed in.
 *
 * Two ways to say yes:
 *
 *  1. The framework already carried the claim across — `FederatedPrincipal::$emailVerified`
 *     is true. That is the OAuth 2.0 catalogue's per-provider flag (GitHub's verified
 *     address list), and an OIDC connection an ORGANIZATION configured whose domain that
 *     organization has proven ({@see OrganizationVouchedEmail}).
 *
 *  2. A CATALOGUE provider the ENVIRONMENT itself configured — "Google, for everybody",
 *     `organization_id` null — or one of the deployment operator's own providers, when the
 *     provider's own token says `email_verified: true`. The framework's domain rule is
 *     about customers: a tenant chooses an organization connection's issuer and could point
 *     it at an IdP that asserts anything, so its word stands only inside a domain it has
 *     proven. An environment provider is set up by the environment's own administrators —
 *     the authority that already owns every account the link could reach — against the
 *     catalogue's pinned issuer, so the claim in the token is Google's, not theirs. Without
 *     this, the commonest case fails: once ANY organization in the environment has
 *     verified the address's domain — the owner's own company domain, typically — the
 *     framework (correctly, for its question) declines to carry Google's `email_verified`
 *     for a connection that organization does not own.
 *
 * And one way for MICROSOFT ENTRA ID, which never sends `email_verified`: the optional
 * `xms_edov` claim set to `true` ({@see EntraVerifiedEmail}). It stands where the provider's
 * `email_verified` would — so an environment's or the operator's own Microsoft provider is
 * taken at its word, and an ORGANIZATION's Entra connection only for an address in a domain
 * that organization has proven, the same boundary the framework draws for any other claim
 * ({@see OrganizationVouchedEmail}).
 *
 * Anything else is no.
 */
final class VouchedEmail
{
    public static function for(FederatedPrincipal $principal, ?Connection $connection): bool
    {
        if ($principal->email === null || $principal->email === '') {
            return false;
        }

        if ($principal->emailVerified === true) {
            return true;
        }

        $entra = EntraVerifiedEmail::for($principal, $connection);

        // An organization's own Entra connection: `xms_edov` counts exactly as far as the
        // framework would let `email_verified` count — inside a domain that organization
        // has proven, and nowhere else.
        if ($entra && $connection !== null && $connection->organization_id !== null) {
            return app(OrganizationVouchedEmail::class)->verified($connection, $principal->email, true) === true;
        }

        $environmentOwnedCatalogue = $connection !== null
            && $connection->organization_id === null
            && $connection->provider !== null
            && ProviderCatalog::find($connection->provider) !== null;

        // An operator provider has no connection at all, and stamps its identities
        // `social:<key>`; its flow records the claim it was given (see OperatorSocialFlow).
        $operator = $connection === null && str_starts_with($principal->provider, 'social:');

        if (! $environmentOwnedCatalogue && ! $operator) {
            return false;
        }

        // Strictly the boolean `true` (OIDC Core §5.1). A string "true" is a provider that
        // does not follow the spec, and guessing what it meant is the wrong side to err on.
        return ($principal->raw['email_verified'] ?? null) === true || $entra;
    }
}
