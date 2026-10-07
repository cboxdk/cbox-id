<?php

declare(strict_types=1);

namespace App\Actions\SignIn;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\OrganizationTarget;
use Cbox\Id\Identity\Contracts\AuthPolicies;

/**
 * The sign-in rules: the environment's baseline, or — with `organization_id` — what governs
 * one organization, and whether that is its own decision or the environment's.
 */
#[AsAction(
    name: 'signin.policy.get',
    summary: 'Read the sign-in rules (password, MFA, SSO, lockout): the environment baseline, or one organization\'s effective rules and override.',
    scope: 'signin:read',
    danger: Danger::Read,
    schema: 'SignInPolicy',
    tag: 'Sign-in',
    rest: ['GET', '/sign-in/policy'],
)]
final readonly class ShowSignInPolicy implements Action
{
    public function __construct(private AuthPolicies $policies) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->max(64)->describe('Read one organization\'s rules. Left out, the environment baseline.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = OrganizationTarget::check($context, $context->nullableString('organization_id'));

        return ActionResult::item($organizationId, AuthPolicyFields::present($this->policies, $organizationId));
    }
}
