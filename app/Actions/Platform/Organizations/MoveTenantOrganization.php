<?php

declare(strict_types=1);

namespace App\Actions\Platform\Organizations;

use App\Actions\Platform\AsOperator;
use App\Actions\Platform\PlatformOrganizationFields;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\OrganizationHierarchy;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Exceptions\CannotReparent;
use Cbox\Id\Organization\Models\Organization;

/**
 * Move an organization in its environment's hierarchy — under another organization of the
 * same environment, or to the top (`parent_id: null`).
 *
 * BOTH IDS ARE RESOLVED THROUGH THE SCOPED READER, inside the named environment, before
 * anything moves. The hierarchy takes bare ids and does not ask which plane they belong
 * to, so an id from another environment reaching `move()` would splice one plane's tenant
 * into another's tree; here it is not found instead. A move that would make a cycle is
 * refused by the hierarchy and nothing is moved.
 */
#[AsAction(
    name: 'platform.organizations.move',
    summary: 'Move an organization under another organization of the same environment, or to the top of its hierarchy.',
    scope: 'operator:organizations:write',
    danger: Danger::Critical,
    plane: ActionPlane::Platform,
    rest: ['PUT', '/environments/{environment_id}/organizations/{organization_id}/parent'],
    consoleRoutes: ['platform.organizations.reparent'],
    consoleGate: ConsoleGate::Operator,
    schema: 'PlatformOrganization',
    tag: 'Organizations',
)]
final readonly class MoveTenantOrganization implements Action
{
    public function __construct(private EnvironmentContext $context) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('environment_id')->inPath(),
            Field::string('organization_id')->inPath(),
            Field::string('parent_id')->nullable()->describe('The new parent, of the same environment; null for the top of the hierarchy.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        AsOperator::id($context->principal);

        $environment = AsOperator::environment($context->string('environment_id'));
        $id = $context->string('organization_id');
        $parentId = $context->nullableString('parent_id');

        $organization = $this->context->runAs($environment, static function () use ($id, $parentId): Organization {
            $organizations = app(Organizations::class);

            $organization = $organizations->find($id) ?? throw ActionRefused::notFound('organization');

            if ($parentId !== null && $organizations->find($parentId) === null) {
                throw ActionRefused::notFound('parent organization');
            }

            try {
                app(OrganizationHierarchy::class)->move($id, $parentId);
            } catch (CannotReparent) {
                throw ActionRefused::because('cycle', 'That would create a cycle in the hierarchy — nothing was moved.', 'parent_id');
            }

            // Re-read: the move rewrote its parent.
            return $organization->fresh() ?? $organization;
        });

        return ActionResult::item($organization, PlatformOrganizationFields::present($organization));
    }
}
