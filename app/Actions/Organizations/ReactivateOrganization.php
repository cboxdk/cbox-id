<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Organization\Contracts\Organizations;

/**
 * Lift an organization's suspension, returning it to active. Recorded on its trail as
 * whoever asked.
 */
#[AsAction(
    name: 'organizations.reactivate',
    summary: 'Lift an organization\'s suspension so its members can sign in again.',
    scope: 'organizations:write',
    danger: Danger::Write,
    schema: 'Organization',
    tag: 'Organizations',
    rest: ['POST', '/organizations/{id}/reactivate'],
    consoleRoutes: ['environment.organizations.reactivate'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ReactivateOrganization implements Action
{
    public function __construct(private Organizations $organizations) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The organization id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('id'));

        $active = $this->organizations->reactivate($organization->id, (string) $context->actor()->id);

        return ActionResult::item($active, OrganizationFields::present($active));
    }
}
