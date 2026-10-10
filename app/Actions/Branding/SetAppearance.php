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
use App\Platform\Appearance\BrandImage;
use App\Platform\Appearance\BrandImages;
use App\Platform\Appearance\BrandImageUpload;
use App\Platform\Appearance\InvalidBrandImage;
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
    summary: 'Set the hosted sign-in theme (preset, colours, corners, type, uploaded logo and favicon) for the environment default or one organization.',
    scope: 'branding:write',
    danger: Danger::Write,
    schema: 'Appearance',
    tag: 'Branding',
    rest: ['PUT', '/branding/appearance'],
    consoleRoutes: ['branding.update', 'environment.branding.update', 'environment.organizations.branding.update'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class SetAppearance implements Action
{
    public function __construct(
        private Organizations $organizations,
        private EnvironmentWorkspace $workspace,
        private BrandImages $images,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->nullable()->max(64)->describe('Theme this organization. Left out, the environment default.'),
            AppearanceFields::theme()->required(),
            AppearanceFields::image(BrandImage::Logo),
            AppearanceFields::image(BrandImage::Favicon),
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

        /*
         * THE IMAGES ARE CHECKED BEFORE ANYTHING IS WRITTEN, so a refused favicon never
         * leaves a half-saved theme or an orphaned logo behind. Each is the bytes
         * themselves, as a data: URI — never a URL: an image on a sign-in page is fetched
         * by every visitor, and one hosted elsewhere tells its host who they all are.
         *
         * @var array<string, BrandImageUpload|null> $images  present = change; null = remove
         */
        $images = [];

        foreach (BrandImage::cases() as $kind) {
            if (! $context->has($kind->value)) {
                continue;
            }

            $given = $context->nullableString($kind->value);

            try {
                $images[$kind->value] = $given === null ? null : BrandImageUpload::fromDataUri($kind, $given);
            } catch (InvalidBrandImage $refused) {
                throw ActionRefused::because('invalid_'.$kind->value, $refused->getMessage(), $kind->value);
            }
        }

        if ($images !== [] && ! $this->images->accepting() && array_filter($images) !== []) {
            throw ActionRefused::because('uploads_unavailable', 'Image uploads are not available on this install: the white-label module that stores them is not enabled.', 'logo');
        }

        $payload = [
            'appearance' => $appearance->toArray(),
            'brand_color' => $appearance->light->primary,
        ];

        /*
         * A LOGO DECISION RETIRES THE OLD URL. `brand_logo_url` is the remote address this
         * field used to take. It is never fetched and no longer drawn; uploading a logo, or
         * removing it, is the administrator's answer to the console's "upload your logo"
         * notice, so the stale address goes with it.
         */
        if (array_key_exists(BrandImage::Logo->value, $images)) {
            $payload['brand_logo_url'] = null;
        }

        foreach ($images as $kind => $upload) {
            if ($upload !== null) {
                $this->images->store($upload, $organizationId);
            } else {
                $this->images->remove(BrandImage::from($kind), $organizationId);
            }
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
