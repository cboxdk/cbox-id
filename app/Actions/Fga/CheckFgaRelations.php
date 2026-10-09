<?php

declare(strict_types=1);

namespace App\Actions\Fga;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Kernel\Authorization\Contracts\FineGrainedAuthorization;
use Cbox\Id\Kernel\Authorization\Exceptions\InvalidTuple;
use Cbox\Id\Kernel\Authorization\ValueObjects\Check;
use Cbox\Id\Kernel\Authorization\ValueObjects\Tuple;

/**
 * Up to 100 checks in one round trip, at one revision, answered in the order asked — what
 * a page that shows twenty documents with an edit button each needs. One refused check
 * (an unknown relation, a malformed one) refuses the batch.
 *
 * Each check is written in the tuple notation — `document:readme#viewer@user:alice` — so a
 * hundred of them fit in the query string a read travels in; the answers come back
 * spelled out field by field.
 */
#[AsAction(
    name: 'fga.check.batch',
    summary: 'Run 1–100 checks in one round trip, each written resource#relation@subject (document:readme#viewer@user:alice), all at the same revision, answered in order.',
    scope: 'fga:read',
    danger: Danger::Read,
    schema: 'FgaCheckBatch',
    tag: FgaFields::TAG,
    rest: ['GET', '/fga/check/batch'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class CheckFgaRelations implements Action
{
    public function __construct(private FineGrainedAuthorization $fga) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::list('checks', Field::string('check')->required()->max(400))
                ->required()->min(1)->max(FgaFields::MAX_BATCH)->describe('1–100 checks, each `resource_type:resource_id#relation@subject_type:subject_id[#subject_relation]`.'),
            FgaFields::consistency(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $results = FgaFields::refusing(function () use ($context): array {
            $checks = [];

            foreach (array_values(array_filter($context->array('checks'), is_string(...))) as $index => $text) {
                try {
                    $tuple = Tuple::parse($text);
                } catch (InvalidTuple $invalid) {
                    throw new InvalidTuple($invalid->getMessage(), $index, 'checks');
                }

                $checks[] = new Check($tuple->resource, $tuple->relation, $tuple->subject);
            }

            return $this->fga->checkMany($checks, $context->nullableString('consistency_token'));
        });

        return ActionResult::item($results, [
            'results' => array_map(FgaFields::presentCheck(...), $results),
            'consistency_token' => $results === [] ? null : (string) $results[0]->consistency,
        ]);
    }
}
