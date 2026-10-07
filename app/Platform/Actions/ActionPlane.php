<?php

declare(strict_types=1);

namespace App\Platform\Actions;

/**
 * Where an action is served: which host its REST route lives on and which credential
 * reaches it. One plane per action — an action that means something on two planes is two
 * actions.
 */
enum ActionPlane: string
{
    /** One environment, on its own host: `/api/v1/…` with an environment key. */
    case Environment = 'environment';
}
