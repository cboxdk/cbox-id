<?php

declare(strict_types=1);

namespace App\Actions\SignIn;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\SignInAudit;
use Cbox\Id\Federation\Enums\ConnectionStatus;

/**
 * Stop offering a social provider, keeping its credentials to turn it back on.
 *
 * On the ENVIRONMENT's provider that takes the button off every sign-in page that inherits
 * it. On an ORGANIZATION's own it takes the button off that organization's page — and does
 * not bring the environment's back in its place: an organization that set up its own Google
 * meant its own, and falling back to the environment's credentials would put its people's
 * accounts somewhere it did not choose. Anyone who signed in with it keeps their account.
 */
#[AsAction(
    name: 'signin.social.disable',
    summary: 'Turn a social login provider off without removing it. People who used it keep their accounts.',
    scope: 'signin:write',
    danger: Danger::Critical,
    schema: 'SocialProvider',
    tag: 'Sign-in',
    rest: ['POST', '/sign-in/social-providers/{id}/disable'],
    consoleRoutes: ['social-providers.disable', 'environment.social-providers.disable'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class TurnOffSocialProvider implements Action
{
    public function __construct(private SignInAudit $audit) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The provider\'s id.'),
            Field::string('organization_id')->nullable()->max(64)->describe('Only if it is this organization\'s; anything else is a 404.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $connection = SocialProviderFields::reachable($context);

        // Recorded once, on the change: turning off a provider that is already off is not
        // a second entry on the trail.
        if ($connection->status !== ConnectionStatus::Inactive) {
            $connection->status = ConnectionStatus::Inactive;
            $connection->save();

            $this->audit->record(SignInAudit::SOCIAL_PROVIDER_DISABLED, $context->actor(), $connection->organization_id, 'connection', $connection->id, [
                'provider' => $connection->provider,
                'name' => $connection->name,
            ]);
        }

        return ActionResult::item($connection, SocialProviderFields::present($connection));
    }
}
