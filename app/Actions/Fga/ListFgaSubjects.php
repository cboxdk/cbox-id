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
use Cbox\Id\Kernel\Authorization\ValueObjects\ResourceRef;

/**
 * "Who can view the readme?" — the subjects of one type that have a relation on a
 * resource, every group and inheritance expanded, as ids sorted and paged. What a share
 * dialog lists.
 */
#[AsAction(
    name: 'fga.subjects.list',
    summary: 'List the subjects of one type that have a relation on a resource (e.g. every user who can view the readme), groups and inheritance expanded; sorted ids, paged with after.',
    scope: 'fga:read',
    danger: Danger::Read,
    schema: 'FgaObject',
    tag: FgaFields::TAG,
    rest: ['GET', '/fga/subjects'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ListFgaSubjects implements Action
{
    public function __construct(private FineGrainedAuthorization $fga) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('resource_type')->required()->max(64)->describe('The resource\'s type: `document`.'),
            Field::string('resource_id')->required()->max(128)->describe('The resource\'s id: `readme`.'),
            Field::string('relation')->required()->max(64)->describe('The relation: `viewer`.'),
            Field::string('subject_type')->required()->max(64)->describe('Which type of subject to list: `user`.'),
            FgaFields::consistency(),
            Field::integer('limit')->describe('Ids per page, 1–1000. Default 100.'),
            Field::string('after')->max(128)->describe('The `next_cursor` of the previous page.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $limit = FgaFields::limit($context, 100, FgaFields::MAX_LIST);
        $list = FgaFields::refusing(fn () => $this->fga->listSubjects(
            ResourceRef::of($context->string('resource_type'), $context->string('resource_id')),
            $context->string('relation'),
            $context->string('subject_type'),
            $context->nullableString('consistency_token'),
            $limit,
            $context->nullableString('after'),
        ));

        return ActionResult::page($list, FgaFields::presentObjects($list), FgaFields::objectMeta($list, $limit));
    }
}
