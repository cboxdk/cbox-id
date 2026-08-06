<?php

declare(strict_types=1);

namespace App\Platform\Install;

use App\Platform\Install\Enums\DeploymentShape;
use App\Platform\PlaneResolver;

/**
 * Everything the installer needs to take an empty database to a usable deployment,
 * decided before anything is written.
 *
 * A plan is deliberately inert: the CLI and the first-run screen both build one from
 * their own input, so the two doors into provisioning cannot drift into provisioning
 * different things.
 */
final readonly class InstallPlan
{
    public function __construct(
        public DeploymentShape $shape,
        public OperatorIdentity $operator,
        public string $environmentName = 'Production',
        public string $organizationName = 'Cbox',
        /**
         * Where the account console lives, in the multi-tenant shape only. Null in the
         * single-tenant shape, where there is no second origin to name — and a
         * multi-tenant deployment WITHOUT one is {@see PlaneResolver::misconfigured()},
         * so the installer refuses that combination rather than writing it down.
         */
        public ?string $consoleHost = null,
    ) {}

    /** The environment lines this plan implies, for writing to (or printing beside) `.env`. */
    public function consoleHostLine(): ?string
    {
        return $this->consoleHost === null ? null : 'CBOX_ID_CONSOLE_HOST='.$this->consoleHost;
    }

    public function multiTenantLine(): string
    {
        return 'CBOX_ID_MULTI_TENANT='.($this->shape->isMultiTenant() ? 'true' : 'false');
    }
}
