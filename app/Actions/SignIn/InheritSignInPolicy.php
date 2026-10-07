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
use App\Platform\Actions\OrganizationTarget;
use App\Platform\SignInAudit;
use Cbox\Id\Identity\Contracts\AuthPolicies;

/**
 * Drop an organization's override, so it goes back to inheriting the environment baseline.
 *
 * Organization-level only: the baseline is what everything else inherits FROM, so there is
 * nothing above it to fall back to — the environment console refuses this before it runs.
 * Critical, because it can loosen the rules in force for that organization in one call.
 */
#[AsAction(
    name: 'signin.policy.inherit',
    summary: 'Drop one organization\'s sign-in rules override, so it inherits the environment baseline again.',
    scope: 'signin:write',
    danger: Danger::Critical,
    tag: 'Sign-in',
    rest: ['DELETE', '/sign-in/policy/organizations/{organization_id}'],
    status: 204,
    consoleRoutes: ['auth-policy.inherit', 'environment.auth-policy.inherit'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class InheritSignInPolicy implements Action
{
    public function __construct(
        private AuthPolicies $policies,
        private SignInAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization whose override to drop.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = (string) OrganizationTarget::check($context, $context->string('organization_id'), inPath: true);

        $this->policies->clearForOrganization($organizationId);

        $this->audit->record(SignInAudit::POLICY_INHERITED, $context->actor(), $organizationId, 'organization', $organizationId);

        return ActionResult::none($organizationId);
    }
}
