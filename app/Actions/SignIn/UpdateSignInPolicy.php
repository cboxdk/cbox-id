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
use App\Platform\Actions\OrganizationTarget;
use App\Platform\RevokingAuthPolicies;
use App\Platform\SignInAudit;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;

/**
 * Change the sign-in rules at one level: the environment's BASELINE every organization
 * inherits, or — with `organization_id` — one organization's OVERRIDE, which may only ever
 * tighten the baseline ({@see AuthPolicy::tightenedWith()}).
 *
 * An override that would loosen any rule is refused whole, every loosened field named:
 * `tightenedWith()` would silently discard the looser value, and a stored rule that is in
 * force nowhere is a console that lies.
 *
 * THROUGH THE CONTRACT, which is what makes session revocation happen at all: it lives in
 * {@see RevokingAuthPolicies}, the container's decorator around {@see AuthPolicies}, so
 * requiring SSO here ends every password session it just made illegitimate — from the
 * console and the API alike.
 */
#[AsAction(
    name: 'signin.policy.update',
    summary: 'Change the sign-in rules of the environment baseline, or tighten one organization\'s override. Requiring SSO signs out password sessions.',
    scope: 'signin:write',
    danger: Danger::Critical,
    schema: 'SignInPolicy',
    tag: 'Sign-in',
    rest: ['PATCH', '/sign-in/policy'],
    consoleRoutes: ['auth-policy.update', 'environment.auth-policy.update'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class UpdateSignInPolicy implements Action
{
    public function __construct(
        private AuthPolicies $policies,
        private SignInAudit $audit,
        private EnvironmentContext $environments,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->nullable()->max(64)->describe('Write this organization\'s override. Left out, the environment baseline.'),
            ...AuthPolicyFields::fields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = OrganizationTarget::check($context, $context->nullableString('organization_id'));

        if ($organizationId === null) {
            $policy = AuthPolicyFields::policy($context, $this->policies->forEnvironment());

            $this->policies->setForEnvironment($policy);
        } else {
            // From what is IN FORCE for the organization, the way the console's form is
            // prefilled: an organization with no override has no values of its own, and
            // starting from an empty policy's defaults would store a 12 under a 16.
            $policy = AuthPolicyFields::policy($context, $this->policies->resolve($organizationId));
            $loosened = AuthPolicyFields::loosenings($policy, $this->policies->forEnvironment());

            if ($loosened !== []) {
                throw ActionRefused::onFields('loosens_environment_baseline', $loosened);
            }

            $this->policies->setForOrganization($organizationId, $policy);
        }

        $this->audit->record(
            SignInAudit::POLICY_UPDATED,
            $context->actor(),
            $organizationId,
            $organizationId === null ? 'environment' : 'organization',
            $organizationId ?? $this->environments->current()?->environmentKey(),
            ['policy' => AuthPolicyFields::toArray($policy)],
        );

        return ActionResult::item($policy, AuthPolicyFields::present($this->policies, $organizationId));
    }
}
