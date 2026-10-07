<?php

declare(strict_types=1);

namespace App\Actions\Branding;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\OrganizationTarget;
use App\Platform\EnvironmentWorkspace;
use Cbox\Id\Organization\Models\Organization;

/**
 * The hosted sign-in theme at one altitude: the environment default every organization
 * inherits, or — with `organization_id` — one organization's own.
 */
#[AsAction(
    name: 'branding.appearance.get',
    summary: 'Read the hosted sign-in theme: the environment default, or one organization\'s own.',
    scope: 'branding:read',
    danger: Danger::Read,
    schema: 'Appearance',
    tag: 'Branding',
    rest: ['GET', '/branding/appearance'],
)]
final readonly class ShowAppearance implements Action
{
    public function __construct(private EnvironmentWorkspace $workspace) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->max(64)->describe('Read this organization\'s theme. Left out, the environment default.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = OrganizationTarget::check($context, $context->nullableString('organization_id'));

        $target = $organizationId === null
            ? $this->workspace->environment()
            : Organization::query()->find($organizationId);

        if ($target === null) {
            throw ActionRefused::notFound($organizationId === null ? 'environment' : 'organization');
        }

        return ActionResult::item($target, AppearanceFields::present($organizationId, $target->settings));
    }
}
