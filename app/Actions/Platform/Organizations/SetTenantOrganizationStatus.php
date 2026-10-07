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
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;

/**
 * Suspend an organization inside an environment, or reactivate it.
 *
 * The state is named rather than flipped, for the reason {@see SetWorkspaceStatus} gives:
 * a retried toggle undoes itself. The organization is resolved through the SCOPED reader
 * inside the named environment, so an id from another plane is not found — and the change
 * is recorded by {@see Organizations::suspend()} itself, as the acting operator.
 *
 * @see \App\Actions\Platform\Workspaces\SetWorkspaceStatus
 */
#[AsAction(
    name: 'platform.organizations.set_status',
    summary: 'Suspend an organization inside an environment (its members can no longer sign in to it) or reactivate it.',
    scope: 'operator:organizations:write',
    danger: Danger::Critical,
    plane: ActionPlane::Platform,
    rest: ['PUT', '/environments/{environment_id}/organizations/{organization_id}/status'],
    consoleRoutes: ['platform.organizations.toggle'],
    consoleGate: ConsoleGate::Operator,
    schema: 'PlatformOrganization',
    tag: 'Organizations',
)]
final readonly class SetTenantOrganizationStatus implements Action
{
    public function __construct(private EnvironmentContext $context) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('environment_id')->inPath(),
            Field::string('organization_id')->inPath(),
            Field::string('status')->required()->oneOf(['active', 'suspended']),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $operatorId = AsOperator::id($context->principal);
        $environment = AsOperator::environment($context->string('environment_id'));
        $id = $context->string('organization_id');
        $suspend = $context->string('status') === 'suspended';

        $organization = $this->context->runAs($environment, static function () use ($id, $suspend, $operatorId): ?Organization {
            $organizations = app(Organizations::class);
            $organization = $organizations->find($id);

            if ($organization === null) {
                return null;
            }

            $active = $organization->status === OrganizationStatus::Active;

            if ($suspend && $active) {
                return $organizations->suspend($organization->id, $operatorId);
            }

            if (! $suspend && ! $active) {
                return $organizations->reactivate($organization->id, $operatorId);
            }

            return $organization;
        }) ?? throw ActionRefused::notFound('organization');

        return ActionResult::item($organization, PlatformOrganizationFields::present($organization));
    }
}
