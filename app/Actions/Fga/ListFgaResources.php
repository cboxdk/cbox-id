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
use Cbox\Id\Kernel\Authorization\ValueObjects\SubjectRef;

/**
 * "Which documents can alice view?" — the resources of one type a subject has a relation
 * on, through every inheritance, as ids sorted and paged. What a list page filters its
 * rows by.
 */
#[AsAction(
    name: 'fga.resources.list',
    summary: 'List the resources of one type a subject has a relation on (e.g. every document alice can view), through all inheritance; sorted ids, paged with after.',
    scope: 'fga:read',
    danger: Danger::Read,
    schema: 'FgaObject',
    tag: FgaFields::TAG,
    rest: ['GET', '/fga/resources'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ListFgaResources implements Action
{
    public function __construct(private FineGrainedAuthorization $fga) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('resource_type')->required()->max(64)->describe('Which type of resource to list: `document`.'),
            Field::string('relation')->required()->max(64)->describe('The relation the subject must have: `viewer`.'),
            Field::string('subject_type')->required()->max(64)->describe('The subject\'s type: `user`.'),
            Field::string('subject_id')->required()->max(128)->describe('The subject\'s id: `alice`.'),
            Field::string('subject_relation')->max(64)->describe('For a userset subject: `member` with `group`/`eng`.'),
            FgaFields::consistency(),
            Field::integer('limit')->describe('Ids per page, 1–1000. Default 100.'),
            Field::string('after')->max(128)->describe('The `next_cursor` of the previous page.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $limit = FgaFields::limit($context, 100, FgaFields::MAX_LIST);
        $list = FgaFields::refusing(fn () => $this->fga->listResources(
            SubjectRef::of($context->string('subject_type'), $context->string('subject_id'), $context->nullableString('subject_relation')),
            $context->string('relation'),
            $context->string('resource_type'),
            $context->nullableString('consistency_token'),
            $limit,
            $context->nullableString('after'),
        ));

        return ActionResult::page($list, FgaFields::presentObjects($list), FgaFields::objectMeta($list, $limit));
    }
}
