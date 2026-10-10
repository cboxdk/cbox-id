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
use App\Platform\Actions\Input\InputSchema;
use App\Platform\SignInAudit;
use Cbox\Id\Identity\Contracts\SmsFactorPolicies;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Turn text-message codes on or off as a second factor for this environment, choose the
 * countries, and set the administrator rule.
 *
 * CRITICAL: turning SMS on admits the weakest second factor the platform has, and the
 * country list is the toll-fraud exposure of whoever pays the SMS bill. Environment-level
 * only — an organization administrator (or a token they signed in for) is refused rather
 * than allowed to widen what the whole environment texts.
 *
 * SMS ON WITH NO COUNTRY is refused rather than stored: the framework would read it as
 * "admit none", and a console showing SMS as on while nobody can enrol is a console that
 * lies.
 *
 * Recorded as `auth_policy.sms_updated` on the environment's trail, as the key or the
 * person that changed it.
 */
#[AsAction(
    name: 'signin.sms.update',
    summary: 'Change the SMS second-factor policy: accept text-message codes or not, the countries they may go to, and whether SMS may be an administrator\'s only factor.',
    scope: 'signin:write',
    danger: Danger::Critical,
    schema: 'SmsFactorPolicy',
    tag: 'Sign-in',
    rest: ['PATCH', '/sign-in/sms'],
    consoleRoutes: ['environment.auth-policy.sms', 'auth-policy.sms'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class UpdateSmsFactorPolicy implements Action
{
    public function __construct(
        private SmsFactorPolicies $policies,
        private SignInAudit $audit,
        private EnvironmentContext $environments,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of(SmsFactorPolicyFields::fields());
    }

    public function handle(ActionContext $context): ActionResult
    {
        if ($context->principal->confinedToOrganization() !== null) {
            throw new AuthorizationException('The SMS policy is the environment\'s; an organization administrator cannot change it.');
        }

        $before = $this->policies->forEnvironment();
        $policy = SmsFactorPolicyFields::policy($context, $before);

        if ($policy->enabled && $policy->allowedCountries === []) {
            throw ActionRefused::because(
                'countries_required',
                'Choose at least one country before turning text-message codes on.',
                'allowed_countries',
            );
        }

        $this->policies->setForEnvironment($policy);

        $this->audit->record(
            SignInAudit::SMS_POLICY_UPDATED,
            $context->actor(),
            null,
            'environment',
            $this->environments->current()?->environmentKey(),
            [
                'from' => SmsFactorPolicyFields::present($before),
                'to' => SmsFactorPolicyFields::present($policy),
            ],
        );

        return ActionResult::item($policy, SmsFactorPolicyFields::present($policy));
    }
}
