<?php

declare(strict_types=1);

namespace App\Platform\Social;

use Cbox\Id\Federation\Enums\FederationProtocol;
use Cbox\Id\Federation\ValueObjects\ProviderTemplate;

/**
 * One social provider the OPERATOR has configured for this deployment.
 *
 * The split mirrors the tenant path's: the catalogue entry supplies everything that is
 * the same for every deployment — issuer, endpoints, scopes, and where the identity sits
 * in the response — and the operator supplies only what is theirs, the client id, the
 * secret, and any parameter the template declares (Entra's directory id).
 *
 * That split is the point of the whole change. It used to be the other way round: a
 * driver in a third-party package held the endpoints and the profile shape, and the
 * platform held its own, separate copy of the same knowledge for tenants. Two copies of
 * "what is GitHub" is two places a fix has to land.
 *
 * Where these credentials LIVE is deliberately not where a tenant's live. A tenant's are
 * customer data: sealed, per-organization, edited from a console. An operator's are
 * deployment configuration — read from the environment, identical for every request, and
 * owned by whoever runs the deployment. They stay in config; nothing here is ever a row.
 */
readonly class OperatorProvider
{
    /**
     * @param  array<string, string>  $parameters  values for the template's declared parameters
     */
    public function __construct(
        public ProviderTemplate $template,
        public string $clientId,
        public string $clientSecret,
        public array $parameters = [],
    ) {}

    public function key(): string
    {
        return $this->template->key;
    }

    /** The provider's human name — "Microsoft Entra ID", not "microsoft". */
    public function label(): string
    {
        return $this->template->name;
    }

    public function isOidc(): bool
    {
        return $this->template->protocol === FederationProtocol::Oidc;
    }

    /**
     * The issuer for this deployment, or null when a declared parameter is missing.
     *
     * Null is why a half-configured provider is never offered: {@see OperatorProviders}
     * drops it rather than rendering a button whose flow cannot complete.
     */
    public function issuer(): ?string
    {
        return $this->template->issuerFor($this->parameters);
    }

    /**
     * The provider string an identity is stored under.
     *
     * `social:google`, unchanged from what the previous driver produced. A STORED value:
     * every operator-level identity ever linked carries it, and a returning user is
     * matched on it. Changing the prefix would not fail a test — it would quietly stop
     * recognising existing users and hand them new accounts.
     */
    public function identityProvider(): string
    {
        return 'social:'.$this->key();
    }
}
