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
 * Suspend an organization: its members are refused at the request pipeline, the device flow
 * and the consent screen until it is reactivated. Recorded on the organization's own trail
 * by the framework, as whoever asked.
 */
#[AsAction(
    name: 'organizations.suspend',
    summary: 'Suspend an organization: its members cannot sign in to it until it is reactivated.',
    scope: 'organizations:write',
    danger: Danger::Write,
    schema: 'Organization',
    tag: 'Organizations',
    rest: ['POST', '/organizations/{id}/suspend'],
    consoleRoutes: ['environment.organizations.suspend'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class SuspendOrganization implements Action
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

        $suspended = $this->organizations->suspend($organization->id, (string) $context->actor()->id);

        return ActionResult::item($suspended, OrganizationFields::present($suspended));
    }
}
