<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\PlatformScopes;

/**
 * A platform OPERATOR — Cbox staff — acting through a token they delegated: the only
 * principal the platform plane ({@see ActionPlane::Platform}) accepts over REST.
 *
 * No management key is one, and none can become one: an environment key and a workspace
 * key speak for a customer, and the platform plane is the deployment above every
 * customer. The delegated-token principal implements this when the person who delegated
 * the token is an ACTIVE operator and the token carries `operator:*` scopes
 * ({@see PlatformScopes}); its {@see self::authorize()} refuses any other plane, the way
 * the key principals do.
 */
interface OperatorPrincipal extends PersonPrincipal
{
    /**
     * The operator record (`platform_operators.id`) behind the person — the actor every
     * platform service records a suspension, a reparenting or a new operator against.
     */
    public function operatorId(): string;
}
