<?php

declare(strict_types=1);

namespace App\Platform\Console;

/**
 * Which door the administrator came through.
 *
 * NOT which console they get. There is one console, and this enum exists so that
 * exactly three things can differ — who authorized them, which organizations they may
 * act on, and whether they had to choose one. Everything past {@see ConsoleScope} is
 * identical, which is the property the two-console split failed to have.
 */
enum ConsolePlane: string
{
    /**
     * A member of the organization, signed in as a SUBJECT of this environment. They
     * administer their own organization and no other, so there is nothing to choose.
     */
    case Organization = 'organization';

    /**
     * An ACCOUNT member administering the environment those organizations live in — in
     * the multi-tenant shape, root/tenant 1. They may act on any organization here, so
     * they pick one, exactly as they already pick an environment.
     */
    case Environment = 'environment';

    /**
     * Whether this plane acts on one organization implicitly or picks among several.
     *
     * The only branch a UI is allowed to make on the plane. Anything else — which fields
     * exist, which providers are offered, which guards run — is the drift this replaced.
     */
    public function choosesOrganization(): bool
    {
        return $this === self::Environment;
    }
}
