<?php

declare(strict_types=1);

namespace App\Actions\SignIn;

use App\Platform\Actions\ActionContext;
use App\Platform\Actions\Input\Field;
use Cbox\Id\Identity\ValueObjects\SmsFactorPolicy;
use Cbox\Id\Otp\Sms\CallingCodes;

/**
 * The environment's SMS second-factor policy as the management API takes and returns it.
 * A helper, not an action.
 */
final class SmsFactorPolicyFields
{
    /**
     * Each optional: one left out keeps its current value.
     *
     * @return list<Field>
     */
    public static function fields(): array
    {
        return [
            Field::boolean('enabled')->describe('Accept text messages as a second factor in this environment. Off by default: SMS is the weakest factor offered.'),
            Field::list('allowed_countries', Field::string('country')->max(2)->oneOf(CallingCodes::countries()))
                ->max(250)
                ->describe('ISO 3166-1 alpha-2 countries whose numbers may enrol and be texted. Required to be non-empty while SMS is on: which countries you text is your toll-fraud exposure.'),
            Field::boolean('privileged_need_stronger_factor')->describe('Administrators may add SMS only beside an authenticator app or a passkey, and are asked to enrol one if SMS is all they hold. On by default.'),
        ];
    }

    /** $base with whatever the caller sent laid over it. */
    public static function policy(ActionContext $context, SmsFactorPolicy $base): SmsFactorPolicy
    {
        return new SmsFactorPolicy(
            enabled: $context->boolean('enabled', $base->enabled),
            allowedCountries: $context->has('allowed_countries')
                ? SmsFactorPolicy::normaliseCountries($context->array('allowed_countries'))
                : $base->allowedCountries,
            privilegedNeedStrongerFactor: $context->boolean('privileged_need_stronger_factor', $base->privilegedNeedStrongerFactor),
        );
    }

    /**
     * `SmsFactorPolicy` in the spec. `deployment_countries` is the ceiling set by whoever
     * runs the deployment (`CBOX_ID_SMS_ALLOWED_COUNTRIES`, empty for none): a country the
     * environment lists outside it is still never texted, and saying so here is how a
     * console avoids promising a country it cannot deliver.
     *
     * @return array{enabled: bool, allowed_countries: list<string>, privileged_need_stronger_factor: bool, deployment_countries: list<string>}
     */
    public static function present(SmsFactorPolicy $policy): array
    {
        return [
            'enabled' => $policy->enabled,
            'allowed_countries' => $policy->allowedCountries,
            'privileged_need_stronger_factor' => $policy->privilegedNeedStrongerFactor,
            'deployment_countries' => self::deploymentCountries(),
        ];
    }

    /** @return list<string> */
    public static function deploymentCountries(): array
    {
        $configured = config('cbox-id.sms.allowed_countries');

        return is_array($configured) ? SmsFactorPolicy::normaliseCountries($configured) : [];
    }
}
