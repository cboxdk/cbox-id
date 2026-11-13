<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionRunner;

/**
 * A principal that proves "still the same person" itself, before a sensitive run — a
 * person at a console, whose proof is the plane's step-up window rather than a token's
 * `auth_time` or a held approval.
 *
 * {@see ActionRunner} asks it once the input is valid and the run is rated, so the
 * question is put by the runner for every door that runs an action as this principal —
 * not by each route remembering to.
 */
interface ConfirmsFreshly
{
    /**
     * Return when the run may go ahead; throw the step-up the person must clear otherwise.
     */
    public function confirmFresh(ActionDefinition $action): void;
}
