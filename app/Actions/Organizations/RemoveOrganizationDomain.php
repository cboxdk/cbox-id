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
use Cbox\Id\Federation\Contracts\DomainVerification;

/**
 * Drop an organization's claim on an email domain — and its capture with it: people on the
 * domain stop being routed to the organization's SSO.
 */
#[AsAction(
    name: 'organizations.domains.remove',
    summary: 'Remove a claimed email domain from an organization. Capture on it ends.',
    scope: 'organizations:write',
    danger: Danger::Destructive,
    tag: 'Organizations',
    rest: ['DELETE', '/organizations/{organization_id}/domains/{domain_id}'],
    status: 204,
    consoleRoutes: ['environment.organizations.domains.remove'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class RemoveOrganizationDomain implements Action
{
    public function __construct(private DomainVerification $domains) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('domain_id')->inPath()->max(64)->describe('The claimed domain\'s id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));
        $domain = DomainFields::find($organization->id, $context->string('domain_id'));

        $this->domains->remove($domain->id);

        return ActionResult::none($domain);
    }
}
