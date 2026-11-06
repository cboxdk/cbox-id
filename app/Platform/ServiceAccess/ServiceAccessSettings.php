<?php

declare(strict_types=1);

namespace App\Platform\ServiceAccess;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;

final readonly class ServiceAccessSettings
{
    public function __construct(private EnvironmentContext $environments) {}

    public function enabled(): bool
    {
        $environment = config('service-access.identity_environment');

        return config('service-access.enabled') === true && is_string($environment) && $environment !== ''
            && $this->environments->current()?->environmentKey() === $environment;
    }

    /** @return array{url: string, token: string} */
    public function connection(bool $product = false): array
    {
        $url = config('service-access.billing_url');
        $token = config($product ? 'service-access.product_billing_token' : 'service-access.billing_token');
        if (! $this->enabled() || ! is_string($url) || ! is_string($token) || trim($token) === '') {
            throw new ServiceAccessUnavailable('Service directory connection is not configured.');
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || (($parts['scheme'] ?? '') !== 'https' && ! (app()->environment('local', 'testing') && ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true)))) {
            throw new ServiceAccessUnavailable('Service directory requires a trusted HTTPS origin.');
        }

        return ['url' => rtrim($url, '/'), 'token' => $token];
    }
}
