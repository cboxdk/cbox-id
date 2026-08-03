<?php

declare(strict_types=1);

namespace App\Platform\Health;

use App\Platform\PlaneResolver;
use Cbox\Id\Console\Contracts\HealthCheck;
use Cbox\Id\Console\ValueObjects\HealthResult;

/**
 * Whether this deployment is configured as the shape it claims to be.
 *
 * Every finding here failed SILENTLY before it was a check. Nothing threw, nothing
 * logged, the app booted and served — and two behaviours degraded in ways that surface
 * hours later as a browser refusing a form or a sign-in landing on the wrong door.
 *
 * Tenancy is host-based and that is not a preference. OpenID Connect discovery lives at
 * `https://host/.well-known/openid-configuration` and the issuer identifier IS the host,
 * so a path-based shape (`/tenant-a/…`) has nowhere conformant to put its metadata. The
 * only two shapes are therefore one host, or one host per tenant.
 */
class TenancyHealthCheck implements HealthCheck
{
    public function __construct(private readonly PlaneResolver $planes) {}

    public function run(): array
    {
        $results = [];

        $stated = config('cbox-id.tenancy.multi_tenant');
        $multiTenant = $this->planes->isMultiTenant();

        if (! is_bool($stated)) {
            $results[] = HealthResult::warn(
                'Tenancy mode is inferred, not stated',
                'Set CBOX_ID_MULTI_TENANT to '.($multiTenant ? 'true' : 'false').'. It is currently being '
                .'derived from whether base domains happen to be listed, and that decides whether the host '
                .'bulkheads exist at all: in the single-tenant shape every host answers as the operator and '
                .'subject plane.',
            );
        } else {
            $results[] = HealthResult::ok(
                $multiTenant ? 'Multi-tenant, stated explicitly' : 'Single-tenant identity provider, stated explicitly',
            );
        }

        if (! $multiTenant) {
            // Nothing below applies: there is one host and no second plane to reach.
            return $results;
        }

        $accountHost = $this->planes->accountHost();

        if ($accountHost === null) {
            $results[] = HealthResult::fail(
                'Multi-tenant, but the account console has no host',
                'Set CBOX_ID_ACCOUNT_HOST. Without it the environment console sends its sign-in handoff to a '
                .'local door instead of the account plane, and the account host is absent from the '
                .'Content-Security-Policy form-action list, so browsers refuse cross-plane sign-out. Neither '
                .'raises an error.',
            );
        } else {
            $results[] = HealthResult::ok('Account console host', $accountHost);
        }

        $bases = config('cbox-id.environments.base_domains', []);
        $bases = is_array($bases) ? $bases : [];

        if ($bases === []) {
            $results[] = HealthResult::warn(
                'Multi-tenant with no base domains — every tenant needs its own domain',
                'Subdomain resolution is off, so each environment is reachable only through an exact custom '
                .'domain you have registered for it. That is a supported shape; it is listed here because a '
                .'tenant with no domain row is simply unreachable, with no error to say so.',
            );
        }

        return $results;
    }
}
