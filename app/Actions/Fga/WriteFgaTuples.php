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
 * Write relationship tuples — `document:readme#viewer@user:alice`, `folder:handbook#parent…`
 * — in one atomic batch of 1 to 100, each checked against the schema. Access they grant is
 * in effect for the next check: pass the `consistency_token` this answers with to a check
 * that must see it.
 *
 * Re-writing a tuple that exists is not an error and is not counted in `written`.
 */
#[AsAction(
    name: 'fga.tuples.write',
    summary: 'Write 1–100 relationship tuples (resource#relation@subject) in one atomic batch, each checked against the schema. Grants access at once; returns a consistency_token for checks that must see it.',
    scope: 'fga:write',
    danger: Danger::Write,
    schema: 'FgaTupleWrite',
    tag: FgaFields::TAG,
    rest: ['POST', '/fga/tuples'],
    consoleRoutes: ['environment.fga.tuples.store'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class WriteFgaTuples implements Action
{
    public function __construct(
        private FineGrainedAuthorization $fga,
        private FgaTrail $trail,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([FgaFields::tuples('write')]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $tuples = FgaFields::tuplesFrom($context);
        $result = FgaFields::refusing(fn () => $this->fga->writeTuples($tuples));

        if ($result->written > 0) {
            $this->trail->record(FgaTrail::TUPLES_WRITTEN, $context->actor(), [
                'written' => $result->written,
                'revision' => $result->consistency->revision,
                'tuples' => array_map(static fn (Tuple $tuple): string => (string) $tuple, $tuples),
            ]);
        }

        return ActionResult::item($result, [
            'written' => $result->written,
            'deleted' => 0,
            'consistency_token' => (string) $result->consistency,
        ]);
    }
}
