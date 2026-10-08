<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;

/**
 * One organization of this environment. An id from another environment is not found.
 */
#[AsAction(
    name: 'organizations.get',
    summary: 'Get one organization of this environment by id.',
    scope: 'organizations:read',
    danger: Danger::Read,
    schema: 'Organization',
    tag: 'Organizations',
    rest: ['GET', '/organizations/{id}'],
)]
final readonly class ShowOrganization implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The organization id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('id'));

        return ActionResult::item($organization, OrganizationFields::present($organization));
    }
}
