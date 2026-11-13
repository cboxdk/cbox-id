<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Approvals\ActionApprovalGate;
use App\Platform\OAuth\ManagementStepUp;

/**
 * An action whose harm depends on WHAT it is asked to change.
 *
 * `#[AsAction(danger: …)]` is the action's floor. Most actions are one thing and the floor
 * is the whole answer. A few edit several things at once, and one of those things has the
 * effect of a Critical sibling: repointing a webhook's URL is a new destination for the
 * environment's events, exactly what `webhooks.create` is; adding a redirect URI to an
 * app is a new place authorization codes are delivered, exactly what `apps.create` is.
 * Rating the whole action Critical would make renaming an app wait for a phone; rating it
 * Write let an agent reach the Critical effect through the side door.
 *
 * So the action rates the run in front of it. {@see ActionRunner} asks after the input is
 * valid and before any gate reads the danger — the token step-up ({@see ManagementStepUp}),
 * the approval hold ({@see ActionApprovalGate}) and the console step-up all read the
 * answer, never the floor. The answer is never below the floor.
 *
 * WHAT GOES HERE: cheap reads only, like {@see Preflight} — the input, and the stored
 * value the input would replace. No write, no outbound call.
 */
interface RatedByInput
{
    public function dangerFor(ActionContext $context): Danger;
}
