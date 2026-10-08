<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Actions;

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
use Cbox\Id\Whitelabel\Contracts\BrandProfiles;
use Cbox\Id\Whitelabel\Models\BrandProfile;
use Cbox\Id\Whitelabel\Support\PaletteTokens;

/**
 * Save the white-label branding at one altitude: the ENVIRONMENT default (`organization_id`
 * null), or one ORGANIZATION's own.
 *
 * WHICH ALTITUDE is the caller's explicit choice and is checked, never defaulted: the
 * environment row is what every organization inherits, so an organization's administrator
 * reaching it would re-brand the sign-in page of every other tenant. A field left out keeps
 * its value; a colour that is neither hex nor oklch is refused, every bad token at once.
 *
 * The images are not here — see {@see BrandingFields}. The console uploads them beside this
 * action; the API leaves them as they are.
 *
 * This action lives in the module, not in `app/Actions`: the module registers itself the
 * way an external package would, and names this directory to the action registry from its
 * own service provider. Removed, its endpoint goes with it.
 */
#[AsAction(
    name: 'branding.whitelabel.set',
    summary: 'Save the white-label branding (palette, app name, email sender, welcome email) of the environment default or one organization.',
    scope: 'branding:write',
    danger: Danger::Write,
    schema: 'WhitelabelBranding',
    tag: 'Branding',
    rest: ['PUT', '/branding/whitelabel'],
    consoleRoutes: ['whitelabel.branding.save', 'environment.whitelabel.branding.save', 'environment.organizations.whitelabel.branding.save'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class SaveBranding implements Action
{
    public function __construct(private BrandProfiles $profiles) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->nullable()->max(64)->describe('Brand this organization. Left out, the environment default.'),
            BrandingFields::palette(),
            Field::string('app_name')->nullable()->max(120)->describe('The product name shown in place of Cbox ID; null to unset.'),
            Field::string('email_from_name')->nullable()->max(120)->describe('The sender name on mail; null to unset.'),
            Field::string('email_template')->nullable()->max(5000)->describe('The welcome email body; blank or null to use the default.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = OrganizationTarget::check($context, $context->nullableString('organization_id'));

        $profile = ($organizationId === null
            ? $this->profiles->forEnvironment()
            : $this->profiles->forOrganization($organizationId))
            ?? new BrandProfile(['organization_id' => $organizationId]);

        $changes = ['organization_id' => $organizationId];

        if ($context->has('palette')) {
            $given = $context->array('palette');
            $clean = [];
            $invalid = [];

            foreach (PaletteTokens::TOKENS as $token) {
                $value = is_string($given[$token] ?? null) ? trim($given[$token]) : '';

                if ($value === '') {
                    continue;
                }

                if (! PaletteTokens::isValidColor($value)) {
                    $invalid['palette.'.$token] = 'Use a hex (#0a2540) or oklch(...) colour.';

                    continue;
                }

                $clean[$token] = $value;
            }

            if ($invalid !== []) {
                throw ActionRefused::onFields('invalid_colour', $invalid);
            }

            $changes['palette'] = $clean;
        }

        foreach (['app_name', 'email_from_name'] as $field) {
            if ($context->has($field)) {
                // Null rather than an empty string: the column means "not set".
                $changes[$field] = $context->nullableString($field) === null ? null : trim($context->string($field));
            }
        }

        if ($context->has('email_template')) {
            $changes['email_templates'] = $profile->email_templates->with('welcome', $context->string('email_template'));
        }

        $profile->fill($changes);
        $this->profiles->save($profile);

        return ActionResult::item($profile, BrandingFields::present($organizationId, $profile));
    }
}
