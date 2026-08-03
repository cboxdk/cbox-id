<?php

declare(strict_types=1);

namespace App\Platform\Install;

use App\Platform\Install\Enums\DeploymentShape;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\Account;
use Cbox\Id\Platform\Models\PlatformOperator;

/**
 * What an install actually produced — returned so the caller reports the deployment it
 * has rather than the one it asked for.
 *
 * The two environment slots are not interchangeable. `root` is the PLATFORM ROOT: the
 * environment the platform's own people (operators, account members) live in as
 * subjects. `tenant` is the first customer-facing IdP, and exists only in the
 * multi-tenant shape — single-tenant has one environment doing both jobs, which is
 * exactly why it is `root` that is never null.
 */
final readonly class InstalledPlatform
{
    public function __construct(
        public DeploymentShape $shape,
        public PlatformOperator $operator,
        public Environment $root,
        public ?Account $account = null,
        public ?Environment $tenant = null,
    ) {}
}
