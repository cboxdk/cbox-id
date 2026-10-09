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
use App\Platform\Fga\FgaTrail;
use Cbox\Id\Kernel\Authorization\Contracts\FineGrainedAuthorization;
use Cbox\Id\Kernel\Authorization\ValueObjects\Tuple;

/**
 * Delete relationship tuples in one atomic batch of 1 to 100. Access they granted ends
 * with the next check — including everything inherited through them (deleting a
 * `parent` tuple takes a folder's viewers off every document in it).
 *
 * Deleting a tuple that is not there is not an error and is not counted in `deleted`.
 */
#[AsAction(
    name: 'fga.tuples.delete',
    summary: 'Delete 1–100 relationship tuples in one atomic batch. Revokes the access they granted, and everything inherited through them, at once.',
    scope: 'fga:write',
    danger: Danger::Destructive,
    schema: 'FgaTupleWrite',
    tag: FgaFields::TAG,
    rest: ['POST', '/fga/tuples/delete'],
    consoleRoutes: ['environment.fga.tuples.destroy'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class DeleteFgaTuples implements Action
{
    public function __construct(
        private FineGrainedAuthorization $fga,
        private FgaTrail $trail,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([FgaFields::tuples('delete')]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $tuples = FgaFields::tuplesFrom($context);
        $result = FgaFields::refusing(fn () => $this->fga->writeTuples([], $tuples));

        if ($result->deleted > 0) {
            $this->trail->record(FgaTrail::TUPLES_DELETED, $context->actor(), [
                'deleted' => $result->deleted,
                'revision' => $result->consistency->revision,
                'tuples' => array_map(static fn (Tuple $tuple): string => (string) $tuple, $tuples),
            ]);
        }

        return ActionResult::item($result, [
            'written' => 0,
            'deleted' => $result->deleted,
            'consistency_token' => (string) $result->consistency,
        ]);
    }
}
