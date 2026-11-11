<?php

declare(strict_types=1);

namespace App\Platform\Health;

use App\Platform\Api\ApiHost;
use App\Platform\Surfaces\Surface;
use App\Platform\Surfaces\Surfaces;
use Cbox\Id\Console\Contracts\HealthCheck;
use Cbox\Id\Console\ValueObjects\HealthResult;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentResolver;
use Throwable;

/**
 * Whether login-host mode ({@see Surfaces}) is configured as a whole.
 *
 * The mode is one variable, and three others have to agree with it. Each disagreement fails
 * quietly at runtime: the console has no host and every page is a 404, discovery names an
 * issuer the login host is not, or a tenant environment answers on the login host's name.
 * So `cbox-id:doctor` and the install's closing checks say it instead.
 */
class LoginHostHealthCheck implements HealthCheck
{
    public function __construct(
        private readonly Surfaces $surfaces,
        private readonly EnvironmentResolver $resolver,
    ) {}

    /**
     * @return list<HealthResult>
     */
    public function run(): array
    {
        $login = $this->surfaces->loginHost();

        if ($login === null) {
            return [HealthResult::ok('Sign-in host', 'Single host: sign-in, the issuer and the console share one. Set CBOX_ID_LOGIN_HOST to split them.')];
        }

        $results = [HealthResult::ok('Login host', $login)];
        $console = $this->surfaces->consoleHost();

        if ($console === null) {
            $results[] = HealthResult::fail(
                'Login host set, but the console has no host',
                'Set CBOX_ID_CONSOLE_HOST to the app host. With a login host the console is served on the app host only, so without one it is served nowhere.',
            );
        } elseif ($console === $login) {
            $results[] = HealthResult::fail(
                'The login host and the app host are the same host',
                "Both are {$login}. CBOX_ID_LOGIN_HOST and CBOX_ID_CONSOLE_HOST must name two hosts; leave CBOX_ID_LOGIN_HOST unset to serve everything from one.",
            );
        }

        if ($login === ApiHost::name()) {
            $results[] = HealthResult::fail(
                'The login host and the API host are the same host',
                "Both are {$login}. The API host serves the API and nothing else, so sign-in would be absent.",
            );
        }

        $issuer = config('cbox-id.issuer');
        $issuerHost = is_string($issuer) && $issuer !== '' ? parse_url($issuer, PHP_URL_HOST) : null;

        if (! is_string($issuerHost) || mb_strtolower($issuerHost) !== $login) {
            $results[] = HealthResult::fail(
                'The issuer is not the login host',
                "Set CBOX_ID_ISSUER to https://{$login}. Discovery and every token of the default environment name the configured issuer, and only the login host answers as it.",
            );
        }

        if ($this->surfaces->of($login) === Surface::Login && $this->resolvesToTenant($login)) {
            $results[] = HealthResult::fail(
                'An environment answers on the login host\'s name',
                "{$login} resolves to an environment that is not the default one (its slug or its custom domain). Rename it, and reserve the slug, before turning the login host on.",
            );
        }

        $rpId = config('cbox-id.webauthn.rp_id');

        if (is_string($rpId) && $rpId !== '' && $login !== mb_strtolower($rpId) && ! str_ends_with($login, '.'.mb_strtolower($rpId))) {
            $results[] = HealthResult::fail(
                'The pinned passkey relying party does not cover the login host',
                "CBOX_ID_WEBAUTHN_RP_ID is {$rpId}, and passkeys are used on {$login}. Pin the login host or a parent domain of it, or unset the pin.",
            );
        }

        return $results;
    }

    private function resolvesToTenant(string $host): bool
    {
        try {
            $resolved = $this->resolver->resolveForHost($host)?->environmentKey();
            $default = $this->resolver->defaultEnvironment()?->environmentKey();
        } catch (Throwable) {
            return false;
        }

        return $resolved !== null && $resolved !== $default;
    }
}
