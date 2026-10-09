<?php

declare(strict_types=1);

namespace App\Actions\SignIn;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Identity\Contracts\SmsFactorPolicies;

/**
 * Whether this environment accepts text messages as a second factor, for which countries,
 * and whether SMS may be an administrator's only factor.
 */
#[AsAction(
    name: 'signin.sms.get',
    summary: 'Read the SMS second-factor policy: whether text-message codes are accepted, for which countries, and the administrator rule.',
    scope: 'signin:read',
    danger: Danger::Read,
    schema: 'SmsFactorPolicy',
    tag: 'Sign-in',
    rest: ['GET', '/sign-in/sms'],
)]
final readonly class ShowSmsFactorPolicy implements Action
{
    public function __construct(private SmsFactorPolicies $policies) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $policy = $this->policies->forEnvironment();

        return ActionResult::item($policy, SmsFactorPolicyFields::present($policy));
    }
}
