<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Actions\SignIn\AuthPolicyFields;
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
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;

/**
 * Require single sign-on for the organization a connection belongs to: from now on its
 * people sign in through their identity provider, and every password session it has is
 * ended.
 *
 * Written through the {@see AuthPolicies} contract, which is what ends those sessions — the
 * revocation lives in a decorator around it. The new override is the organization's
 * EXISTING policy with the mandate raised — its own override if it has one, otherwise the
 * environment baseline — so turning this on never quietly restates a password rule.
 *
 * AN ORGANIZATION'S POLICY: an environment-owned connection belongs to none, and the
 * equivalent for the environment is its own sign-in rules (`signin.policy.update`) — a
 * control named "require SSO for this organization" must not change every tenant's.
 */
#[AsAction(
    name: 'sso.connections.require_sso',
    summary: 'Require single sign-on for the organization an SSO connection belongs to. Ends every password session there.',
    scope: 'sso:write',
    danger: Danger::Critical,
    schema: 'SignInPolicy',
    tag: 'Single sign-on',
    rest: ['POST', '/sso/connections/{id}/require-sso'],
    consoleRoutes: ['connections.require-sso', 'environment.connections.require-sso'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class RequireSso implements Action
{
    public function __construct(
        private AuthPolicies $policies,
        private SignInAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The connection\'s id.'),
            EnterpriseReach::narrowField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $connection = SsoFields::changeable($context);
        $organizationId = $connection->organization_id;

        if ($organizationId === null) {
            throw ActionRefused::because('environment_connection', 'This connection belongs to the environment, not to one organization. Set the requirement under Sign-in rules.');
        }

        $current = $this->policies->overrideFor($organizationId) ?? $this->policies->forEnvironment();

        $policy = new AuthPolicy(
            minLength: $current->minLength,
            requireBreachCheck: $current->requireBreachCheck,
            maxAgeDays: $current->maxAgeDays,
            reuseHistory: $current->reuseHistory,
            mfa: $current->mfa,
            sso: SsoEnforcement::Required,
            lockoutThreshold: $current->lockoutThreshold,
        );

        $this->policies->setForOrganization($organizationId, $policy);

        $this->audit->record(SignInAudit::POLICY_UPDATED, $context->actor(), $organizationId, 'organization', $organizationId, [
            'policy' => AuthPolicyFields::toArray($policy),
            'connection_id' => $connection->id,
        ]);

        return ActionResult::item($policy, AuthPolicyFields::present($this->policies, $organizationId));
    }
}
