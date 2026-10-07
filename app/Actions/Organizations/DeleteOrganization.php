<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\PlatformRoot;

/**
 * Archive an organization — {@see Organizations::archive()}, the framework's verb: the soft,
 * terminal state that keeps the rows for the audit trail, refuses every member from the next
 * request, forgets the organization's cached environment resolution and announces
 * `organization.deleted`. Idempotent: archiving an archived organization answers with it as
 * it is and records nothing further.
 *
 * An organization that owns identity-provider PRODUCTS is a customer of this platform, with
 * projects, environments and a bill; closing one is not done here (`owns_products`).
 */
#[AsAction(
    name: 'organizations.delete',
    summary: 'Archive an organization: everyone in it loses access at once. The records are kept for the audit trail.',
    scope: 'organizations:write',
    danger: Danger::Destructive,
    schema: 'Organization',
    tag: 'Organizations',
    rest: ['DELETE', '/organizations/{id}'],
    consoleRoutes: ['environment.organizations.destroy'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class DeleteOrganization implements Action
{
    public function __construct(
        private Organizations $organizations,
        private OrganizationProjects $projects,
        private PlatformRoot $platformRoot,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The organization id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('id'));

        $ownsProducts = $this->platformRoot->run(
            fn (): bool => $this->projects->forOrganization($organization->id)->isNotEmpty(),
        ) === true;

        if ($ownsProducts) {
            throw new ActionRefused(
                'owns_products',
                'This organization owns identity-provider projects on this platform and cannot be archived here.',
                409,
            );
        }

        $archived = $this->organizations->archive($organization->id, (string) $context->actor()->id);

        return ActionResult::item($archived, OrganizationFields::present($archived));
    }
}
