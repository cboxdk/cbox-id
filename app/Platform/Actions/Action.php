<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Input\InputSchema;

/**
 * One thing the platform does, whichever door asks for it.
 *
 * An action is a class carrying {@see AsAction}: it declares its input once, checks what
 * only it can check (does this API exist here, may this app be linked to it), makes the
 * change through the domain services, and returns an {@see ActionResult}. It never knows
 * whether a person clicked a button or an agent called a tool — authorization, validation,
 * transactions and idempotency are {@see ActionRunner}'s, and the same for every door.
 *
 * Refusals are {@see ActionRefused}: a code and a sentence, which the REST door turns into
 * `{error, message}` and the console into a message on the field it names.
 */
interface Action
{
    public static function input(): InputSchema;

    /**
     * @throws ActionRefused
     */
    public function handle(ActionContext $context): ActionResult;
}
