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
use Cbox\Id\Kernel\Authorization\ValueObjects\TupleFilter;

/**
 * The tuples stored, oldest first, filtered by any part of them — every filter an exact
 * match on an index column. These are the facts as written; what they ADD UP to is what
 * a check or a list query answers.
 */
#[AsAction(
    name: 'fga.tuples.list',
    summary: 'List stored relationship tuples oldest first, filtered by resource type and id, relation and subject; pass next_cursor as after to page.',
    scope: 'fga:read',
    danger: Danger::Read,
    schema: 'FgaTuple',
    tag: FgaFields::TAG,
    rest: ['GET', '/fga/tuples'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ListFgaTuples implements Action
{
    public function __construct(private FineGrainedAuthorization $fga) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('resource_type')->max(64)->describe('Only tuples on this resource type.'),
            Field::string('resource_id')->max(128)->describe('Only tuples on this resource.'),
            Field::string('relation')->max(64)->describe('Only tuples of this relation.'),
            Field::string('subject_type')->max(64)->describe('Only tuples naming this subject type.'),
            Field::string('subject_id')->max(128)->describe('Only tuples naming this subject.'),
            Field::string('subject_relation')->max(64)->describe('Only tuples naming a userset with this relation.'),
            Field::integer('limit')->describe('Tuples per page, 1–100. Default 50.'),
            Field::string('after')->max(64)->describe('The `next_cursor` of the previous page.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $limit = FgaFields::limit($context, 50, 100);
        $page = $this->fga->tuples(new TupleFilter(
            $context->nullableString('resource_type'),
            $context->nullableString('resource_id'),
            $context->nullableString('relation'),
            $context->nullableString('subject_type'),
            $context->nullableString('subject_id'),
            $context->nullableString('subject_relation'),
        ), $limit, $context->nullableString('after'));

        return ActionResult::page($page, array_map(FgaFields::presentTuple(...), $page->tuples), [
            'limit' => $limit,
            'has_more' => $page->nextCursor !== null,
            'next_cursor' => $page->nextCursor,
            'consistency_token' => (string) $page->consistency,
        ]);
    }
}
