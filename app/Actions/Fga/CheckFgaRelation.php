<?php

declare(strict_types=1);

namespace App\Actions\Fga;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Kernel\Authorization\Contracts\FineGrainedAuthorization;

/**
 * "May alice edit the readme?" — one check, through every inheritance the schema defines:
 * computed relations, parent folders, nested groups. `allowed` is the answer; a type or
 * relation the schema does not define is refused (`unknown_relation`), never answered
 * "no".
 *
 * The subject is flat (`subject_type`, `subject_id`, `subject_relation`) because a check
 * is a read and travels in the query string, like the list queries beside it.
 */
#[AsAction(
    name: 'fga.check',
    summary: 'Check whether a subject has a relation on a resource — directly, through computed relations, parents or nested groups. Optionally at least as fresh as a consistency_token.',
    scope: 'fga:read',
    danger: Danger::Read,
    schema: 'FgaCheck',
    tag: FgaFields::TAG,
    rest: ['GET', '/fga/check'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class CheckFgaRelation implements Action
{
    public function __construct(private FineGrainedAuthorization $fga) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([...FgaFields::checkFields(), FgaFields::consistency()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $result = FgaFields::refusing(fn () => $this->fga->check(FgaFields::flatCheckFrom($context), $context->nullableString('consistency_token')));

        return ActionResult::item($result, FgaFields::presentCheck($result));
    }
}
