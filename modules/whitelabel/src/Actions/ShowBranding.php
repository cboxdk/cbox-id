<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Actions;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\OrganizationTarget;
use Cbox\Id\Whitelabel\Contracts\BrandProfiles;

/**
 * The white-label branding at one altitude: the environment default every organization
 * inherits, or — with `organization_id` — one organization's own.
 */
#[AsAction(
    name: 'branding.whitelabel.get',
    summary: 'Read the white-label branding (palette, app name, email sender, logo) of the environment default or one organization.',
    scope: 'branding:read',
    danger: Danger::Read,
    schema: 'WhitelabelBranding',
    tag: 'Branding',
    rest: ['GET', '/branding/whitelabel'],
    // Read by the console's Branding page at the altitude it edits — an organization's
    // administrator reads their own organization's, as they may write it.
    consoleGate: ConsoleGate::Administer,
)]
final readonly class ShowBranding implements Action
{
    public function __construct(private BrandProfiles $profiles) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->max(64)->describe('Read this organization\'s branding. Left out, the environment default.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = OrganizationTarget::check($context, $context->nullableString('organization_id'));

        $profile = $organizationId === null
            ? $this->profiles->forEnvironment()
            : $this->profiles->forOrganization($organizationId);

        return ActionResult::item($profile, BrandingFields::present($organizationId, $profile));
    }
}
