<?php

declare(strict_types=1);

namespace App\Actions\SignIn;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AdminSignInLink;
use App\Platform\EnvironmentOwner;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Whether the environment's sign-in page carries the link for administrators
 * ({@see AdminSignInLink}).
 *
 * The environment's alone, like the sign-up switch beside it: it is about the one sign-in
 * page every organization in the environment shares. Recorded on the trail of the
 * workspace that owns the environment and refused when there is none, before the write. A
 * switch to the value already chosen changes nothing and records nothing.
 */
#[AsAction(
    name: 'signin.admin_link.set',
    summary: 'Show or hide the link for administrators on the environment\'s sign-in page. It leads to the environment console.',
    scope: 'signin:write',
    danger: Danger::Write,
    tag: 'Sign-in',
    rest: ['PUT', '/sign-in/admin-link'],
    consoleRoutes: ['environment.auth-policy.admin-link'],
    schema: 'AdminSignInLink',
)]
final readonly class SetAdminSignInLink implements Action
{
    public function __construct(
        private AdminSignInLink $link,
        private EnvironmentOwner $workspace,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::boolean('enabled')->required()->describe('On: the sign-in page shows the link on every host of the environment. Off: on none. Until this is set, the link shows on the environment\'s address under the tenant base domain and not on a custom domain.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $environment = $this->workspace->environment() ?? throw ActionRefused::notFound('environment');

        if ($this->workspace->organizationOf($environment->id) === null) {
            throw new AuthorizationException('This environment belongs to no organization, so there is nowhere to record the change.');
        }

        $enabled = $context->boolean('enabled');

        if ($this->link->set($environment, $enabled)) {
            $this->workspace->record(
                $context->principal,
                $environment->id,
                $enabled ? 'environment.admin_sign_in_link_enabled' : 'environment.admin_sign_in_link_disabled',
            );
        }

        return ActionResult::item($enabled, ['enabled' => $enabled]);
    }
}
