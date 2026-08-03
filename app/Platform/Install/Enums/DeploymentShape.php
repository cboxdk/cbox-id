<?php

declare(strict_types=1);

namespace App\Platform\Install\Enums;

use App\Platform\PlaneResolver;

/**
 * The shape a deployment is being installed in — the one question at install time
 * that nothing downstream can infer for itself.
 *
 * {@see PlaneResolver::isMultiTenant()} reads a STATED flag before it derives anything,
 * because the mode decides whether the host bulkheads exist at all. An installer that
 * left the flag unset would hand the deployment the compatibility fallback (derive from
 * `base_domains`), which is exactly the silent, inferred security control that flag was
 * introduced to replace. So the installer asks, and writes the answer down.
 */
enum DeploymentShape: string
{
    /**
     * One host, one IdP, no account plane. The lone environment IS the platform root,
     * and the plane gates are no-ops — the shape a self-hosted install has.
     */
    case SingleTenant = 'single-tenant';

    /**
     * The SaaS shape: an account plane on its own host that provisions workspaces, and
     * tenant environments on their own hosts. Needs somewhere for the account console
     * to live, or the deployment is {@see PlaneResolver::misconfigured()}.
     */
    case MultiTenant = 'multi-tenant';

    public function label(): string
    {
        return match ($this) {
            self::SingleTenant => 'Single-tenant — one host, one identity provider',
            self::MultiTenant => 'Multi-tenant — an account plane that provisions IdPs',
        };
    }

    public function isMultiTenant(): bool
    {
        return $this === self::MultiTenant;
    }
}
