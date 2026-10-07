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
use App\Platform\EnvironmentWorkspace;
use App\Platform\SelfServiceSignup;
use App\Platform\SignupPolicy;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The environment's own "let people sign themselves up" switch ({@see SelfServiceSignup}).
 *
 * The environment's alone: it decides who may create an account anywhere in it, every
 * organization included, so an organization's administrator has no say in it and the
 * console offers it on the environment console only.
 *
 * Recorded on the trail of the workspace that owns the environment, as the console has
 * always recorded it — and refused when there is none, BEFORE the write, so there is no path
 * that opens the door and then has nowhere to say so. A switch to the value it already has
 * changes nothing and records nothing.
 */
#[AsAction(
    name: 'signin.self_service_signup.set',
    summary: 'Switch self-service sign-up on or off: whether people can create an account and their own organization in this environment.',
    scope: 'signin:write',
    danger: Danger::Critical,
    tag: 'Sign-in',
    rest: ['PUT', '/sign-in/self-service-signup'],
    consoleRoutes: ['environment.auth-policy.self-service-signup'],
)]
final readonly class SetSelfServiceSignup implements Action
{
    public function __construct(
        private SelfServiceSignup $selfService,
        private SignupPolicy $signupPolicy,
        private EnvironmentWorkspace $workspace,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::boolean('enabled')->required()->describe('On: anyone may create an account and their own organization. Off: people join by invitation.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $environment = $this->workspace->environment() ?? throw ActionRefused::notFound('environment');

        if ($this->workspace->workspaceOf($environment->id) === null) {
            throw new AuthorizationException('This environment belongs to no workspace, so there is nowhere to record the change.');
        }

        $enabled = $context->boolean('enabled');

        if ($this->selfService->set($environment, $enabled)) {
            $this->workspace->record(
                $context->principal,
                $environment->id,
                $enabled ? 'environment.self_service_signup_enabled' : 'environment.self_service_signup_disabled',
            );
        }

        return ActionResult::item($enabled, [
            'enabled' => $enabled,
            // False on a single-tenant install, where sign-up follows CBOX_ID_SIGNUP_MODE and
            // the switch changes nothing — said rather than left for the caller to discover.
            'decided_here' => $this->signupPolicy->decidedByEnvironment(),
        ]);
    }
}
