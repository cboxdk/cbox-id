<?php

declare(strict_types=1);

namespace App\Actions\SignIn;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionMessage;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\EnvironmentWorkspace;
use App\Platform\SignInAudit;
use App\Platform\SignInThrough;
use Cbox\Id\Federation\Contracts\Connections;

/**
 * Make this environment's sign-in page another identity provider's ({@see SignInThrough}).
 *
 * With a connection named, `/login` sends everybody straight to it — a person with a session
 * there comes back signed in without seeing a page — and `/login/direct` stays the
 * environment's own page with every method it has, for when that provider is down. With
 * `connection_id: null` the sign-in page is the environment's own again.
 *
 * The connection is one this environment owns outright: OpenID Connect, the environment's
 * own rather than one organization's (an organization's connection signing in everybody
 * else would hand its identity provider the whole environment), and active, so the switch
 * never points the door at something that cannot open it. Created with
 * `sso.connections.create` and `environment_wide: true`, then activated.
 *
 * The environment's alone, like its sign-in rules: an organization administrator has no say
 * in it. A switch to the value it already has changes nothing and records nothing.
 */
#[AsAction(
    name: 'signin.through.set',
    summary: 'Send this environment\'s sign-in page straight to one of its own OpenID Connect connections (or back to its own page with null). /login/direct always stays the environment\'s own page.',
    scope: 'signin:write',
    danger: Danger::Critical,
    tag: 'Sign-in',
    rest: ['PUT', '/sign-in/through'],
    schema: 'SignInThrough',
)]
final readonly class SetSignInThrough implements Action
{
    public function __construct(
        private SignInThrough $through,
        private Connections $connections,
        private EnvironmentWorkspace $workspace,
        private SignInAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('connection_id')->nullable()->max(64)->describe('An active OpenID Connect connection that belongs to the whole environment (sso.connections.create with environment_wide: true). Null for the environment\'s own sign-in page.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $environment = $this->workspace->environment() ?? throw ActionRefused::notFound('environment');

        $id = $context->nullableString('connection_id');
        $id = $id === null || trim($id) === '' ? null : trim($id);

        $connection = null;

        if ($id !== null) {
            // Looked up in THIS environment: another environment's id is a 404 like any other.
            $connection = $this->connections->byId($id) ?? throw ActionRefused::notFound('connection');

            if (! SignInThrough::usable($connection)) {
                throw ActionRefused::because(
                    'connection_not_eligible',
                    new ActionMessage('console.sign_in_through_needs_an_active_environment_oidc_connection_7f3a2c1e'),
                    'connection_id',
                );
            }
        }

        $previous = SignInThrough::configuredFor($environment);

        if ($this->through->set($environment, $id)) {
            $this->audit->record(
                SignInAudit::SIGN_IN_THROUGH_UPDATED,
                $context->actor(),
                null,
                'connection',
                $id ?? $previous,
                ['connection_id' => $id, 'previous_connection_id' => $previous],
            );
        }

        return ActionResult::item($id, [
            'connection_id' => $id,
            'connection_name' => $connection?->name,
            'login_url' => url('/login'),
            'direct_login_url' => url('/login/direct'),
        ]);
    }
}
