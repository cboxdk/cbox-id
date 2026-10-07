<?php

declare(strict_types=1);

namespace App\Actions\Branding;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\OrganizationTarget;
use App\Platform\Appearance\Appearance;
use App\Platform\EnvironmentWorkspace;
use Cbox\Id\Organization\Contracts\Organizations;

/**
 * Set the hosted sign-in theme at one altitude: the ENVIRONMENT default every organization
 * inherits, or — with `organization_id` — one ORGANIZATION's own, which wins wholesale.
 *
 * Which altitude is an explicit choice rather than implied by the door: an organization's
 * administrator reaches only their own organization, and the environment default is the
 * environment's administrators' alone — on the organization plane one tenant could
 * otherwise re-theme every other tenant's sign-in page.
 *
 * AN UNREADABLE PALETTE IS REFUSED, not warned about: the people who cannot read the
 * sign-in page are not the person choosing the colours, they are that organization's users,
 * and a warning somebody clicks past puts the consequence on people who never saw it.
 */
#[AsAction(
    name: 'branding.appearance.set',
    summary: 'Set the hosted sign-in theme (preset, colours, corners, type, logo) for the environment default or one organization.',
    scope: 'branding:write',
    danger: Danger::Write,
    schema: 'Appearance',
    tag: 'Branding',
    rest: ['PUT', '/branding/appearance'],
    consoleRoutes: ['appearance.update', 'environment.appearance.update'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class SetAppearance implements Action
{
    public function __construct(
        private Organizations $organizations,
        private EnvironmentWorkspace $workspace,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->nullable()->max(64)->describe('Theme this organization. Left out, the environment default.'),
            AppearanceFields::theme()->required(),
            Field::string('logo')->nullable()->max(2048)->format('uri')->describe('An https URL for the logo; null removes it, left out keeps it.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = OrganizationTarget::check($context, $context->nullableString('organization_id'));
        $appearance = Appearance::fromArray($context->array('theme'));

        $failures = [
            ...array_map(static fn (string $why): string => 'Light mode: '.$why, $appearance->light->contrastFailures()),
            ...array_map(static fn (string $why): string => 'Dark mode: '.$why, $appearance->dark->contrastFailures()),
        ];

        if ($failures !== []) {
            throw ActionRefused::because('unreadable_palette', implode(' ', $failures), 'theme');
        }

        $logo = $context->nullableString('logo');

        if ($logo !== null && ! AppearanceFields::secureLogo(trim($logo))) {
            throw ActionRefused::because('insecure_logo', 'The logo must be an https URL.', 'logo');
        }

        $payload = [
            'appearance' => $appearance->toArray(),
            'brand_color' => $appearance->light->primary,
        ];

        // Left out, the logo stays as it is; null removes it.
        if ($context->has('logo')) {
            $payload['brand_logo_url'] = $logo === null ? null : trim($logo);
        }

        if ($organizationId === null) {
            $environment = $this->workspace->environment() ?? throw ActionRefused::notFound('environment');
            $environment->settings = array_merge($environment->settings, $payload);
            $environment->save();

            return ActionResult::item($environment, AppearanceFields::present(null, $environment->settings));
        }

        $organization = $this->organizations->updateSettings($organizationId, $payload);

        return ActionResult::item($organization, AppearanceFields::present($organizationId, $organization->settings));
    }
}
