<?php

declare(strict_types=1);

namespace App\Platform\Social;

use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Federation\ValueObjects\ProviderTemplate;

/**
 * The operator's own social providers, resolved from deployment configuration against
 * the shared provider catalogue.
 *
 * A provider is offered only when it is COMPLETELY configured — credentials, plus every
 * parameter its catalogue entry declares. Anything less is dropped rather than rendered,
 * because the alternative is a button on the sign-in page that sends someone to a
 * provider and fails at the callback, where they can do nothing about it.
 */
class OperatorProviders
{
    /**
     * The catalogue keys an operator may configure this way.
     *
     * Deliberately a short allow-list rather than "whatever the catalogue holds". The
     * catalogue also describes providers this path cannot drive: Apple's client secret is
     * an ES256 JWT minted per request rather than a value to paste, and it POSTs its
     * callback instead of redirecting. Offering those here would produce a button that
     * cannot work, so a key that is not named here is not offered — deny by default,
     * exactly as an unregistered connection type is.
     *
     * @var list<string>
     */
    public const SUPPORTED = ['google', 'github', 'microsoft'];

    /**
     * Every fully-configured provider, in catalogue order.
     *
     * @return list<OperatorProvider>
     */
    public function all(): array
    {
        $providers = [];

        foreach (self::SUPPORTED as $key) {
            $provider = $this->find($key);

            if ($provider !== null) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * The named provider, or null when it is unknown, unsupported, or not fully
     * configured. Callers treat null as 404: there is nothing to distinguish, from
     * outside, between a provider this deployment does not offer and one that does not
     * exist.
     */
    public function find(string $key): ?OperatorProvider
    {
        if (! in_array($key, self::SUPPORTED, true)) {
            return null;
        }

        $template = ProviderCatalog::find($key);

        if ($template === null) {
            return null;
        }

        $clientId = $this->setting($key, 'client_id');
        $clientSecret = $this->setting($key, 'client_secret');

        if ($clientId === null || $clientSecret === null) {
            return null;
        }

        $parameters = $this->parameters($template);

        if ($parameters === null) {
            return null;
        }

        $provider = new OperatorProvider(
            template: $template,
            clientId: $clientId,
            clientSecret: $clientSecret,
            parameters: $parameters,
        );

        // An OIDC provider whose issuer will not resolve cannot discover its endpoints,
        // so the flow would die at the first outbound call. Refuse it here instead.
        if ($provider->isOidc() && $provider->issuer() === null) {
            return null;
        }

        return $provider;
    }

    /**
     * The values for a template's declared parameters, or null when any is missing.
     *
     * Read generically from the template rather than by name: Entra declares `directory`
     * today, and a provider that declares another parameter tomorrow is configured the
     * same way without a code change here.
     *
     * @return array<string, string>|null
     */
    private function parameters(ProviderTemplate $template): ?array
    {
        $values = [];

        foreach ($template->parameters as $parameter) {
            $value = $this->setting($template->key, $parameter->key);

            if ($value === null) {
                return null;
            }

            $values[$parameter->key] = $value;
        }

        return $values;
    }

    private function setting(string $key, string $name): ?string
    {
        $value = config("services.{$key}.{$name}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
