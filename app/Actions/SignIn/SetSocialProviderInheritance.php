<?php

declare(strict_types=1);

namespace App\Actions\SignIn;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseReach;
use App\Platform\SignInAudit;
use Cbox\Id\Federation\Contracts\SignInProviders;

/**
 * Whether one organization's sign-in page offers one of its ENVIRONMENT's social providers.
 *
 * Every organization inherits the environment's providers. One that does not want, say,
 * GitHub on its page — a bank whose people should not be signing in with a personal account
 * — turns it off here, without setting up a GitHub of its own. Keyed on the provider, not on
 * one connection, so it holds when the environment's GitHub is removed and set up again.
 *
 * A BUTTON, NOT A LOCK. This decides what the organization's sign-in page shows. Somebody
 * who signs in with the environment's GitHub elsewhere is still signed in; an organization
 * that must keep every other way out requires SSO. Recorded either way, on the
 * organization's trail.
 */
#[AsAction(
    name: 'signin.social.inherit',
    summary: 'Turn one of the environment\'s social login providers off (or back on) for one organization\'s sign-in page.',
    scope: 'signin:write',
    danger: Danger::Write,
    schema: 'OfferedSocialProviders',
    tag: 'Sign-in',
    rest: ['PUT', '/sign-in/social-providers/inherited/{provider}'],
    consoleRoutes: ['social-providers.inherit', 'environment.social-providers.inherit'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class SetSocialProviderInheritance implements Action
{
    public function __construct(
        private SignInProviders $providers,
        private SignInAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('provider')->inPath()->max(100)->describe('The catalogue key: google, github…'),
            Field::string('organization_id')->required()->max(64)->describe('The organization whose sign-in page this is about.'),
            Field::boolean('offered')->required()->describe('False to stop offering the environment\'s provider on this organization\'s page; true to offer it again.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $template = SocialProviderFields::loginTemplate($context->string('provider'))
            ?? throw ActionRefused::notFound('provider');

        $organizationId = EnterpriseReach::requiredOrganization($context);
        $offered = $context->boolean('offered');

        $changed = $offered
            ? $this->providers->resumeInheriting($organizationId, $template->key)
            : $this->providers->stopInheriting($organizationId, $template->key);

        if ($changed) {
            $this->audit->record(
                $offered ? SignInAudit::SOCIAL_PROVIDER_INHERITED : SignInAudit::SOCIAL_PROVIDER_NOT_INHERITED,
                $context->actor(),
                $organizationId,
                'organization',
                $organizationId,
                ['provider' => $template->key],
            );
        }

        return ActionResult::item($organizationId, SocialProviderFields::offered($this->providers, $organizationId));
    }
}
