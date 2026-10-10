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
use Cbox\Id\Federation\Contracts\Connections;

/**
 * Offer a social provider that was turned off again, with the credentials it already has.
 *
 * Through the framework's activation, which is the one write that announces a provider going
 * live — `connection.activated` on the trail and to webhooks, once, on the change.
 */
#[AsAction(
    name: 'signin.social.enable',
    summary: 'Turn a social login provider that was turned off back on, with the credentials it already has.',
    scope: 'signin:write',
    danger: Danger::Write,
    schema: 'SocialProvider',
    tag: 'Sign-in',
    rest: ['POST', '/sign-in/social-providers/{id}/enable'],
    consoleRoutes: ['social-providers.enable', 'environment.social-providers.enable'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class TurnOnSocialProvider implements Action
{
    public function __construct(private Connections $connections) {}

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

        $this->connections->activate($connection->organization_id, $connection->id);

        $fresh = $this->connections->byId($connection->id) ?? $connection;

        return ActionResult::item($fresh, SocialProviderFields::present($fresh));
    }
}
