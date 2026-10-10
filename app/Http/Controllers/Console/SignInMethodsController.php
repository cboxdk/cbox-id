<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Platform\Console\Vocabulary;
use App\Platform\CurrentEnvironment;
use App\Platform\Help\HelpTopic;
use App\Platform\Radar\Enums\RadarMode;
use App\Platform\Radar\RadarPolicy;
use App\Platform\SelfServiceSignup;
use App\Platform\SignupPolicy;
use App\Platform\Turnstile;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\SmsFactorPolicies;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Inertia\Response;

/**
 * AUTHENTICATION › SIGN-IN METHODS — every way into this environment on one page: whether
 * it is on, what it is set to, and where it is changed.
 *
 * WHY A PAGE THAT CHANGES NOTHING. The question a new administrator asks first — "where do
 * I turn passkeys on?", "is magic link on?", "how long do sessions last?" — had no single
 * answer. Password rules and two-factor were on Authentication policy, SMS codes further down
 * the same page, social providers on Social login, enterprise connections on Enterprise SSO,
 * and the half that this console does not decide at all — passkeys and magic links are
 * always offered, session lifetime is the deployment's — was written down nowhere a person
 * would look. WorkOS answers it with an Authentication › Methods page, Clerk with "User &
 * authentication"; this is ours.
 *
 * READ-ONLY ON PURPOSE. Each row links to the page whose form changes it, so there is still
 * exactly one writer for each setting (and every write is still the Action behind that
 * page). A row whose answer is the DEPLOYMENT's says so and names the variable, rather than
 * offering a switch the console cannot honour.
 *
 * ENVIRONMENT PLANE ONLY. It reports the environment's baseline; an organization can only
 * tighten it ({@see AuthPolicyController}), and the table of who did is one link away.
 */
final readonly class SignInMethodsController extends ConsoleController
{
    public function __invoke(
        AuthPolicies $policies,
        SmsFactorPolicies $sms,
        RadarPolicy $radar,
        SignupPolicy $signup,
        Turnstile $turnstile,
        CurrentEnvironment $environment,
    ): Response {
        $this->scope->assertMayAdministerEnvironment();

        $policy = $policies->forEnvironment();
        $smsPolicy = $sms->forEnvironment();

        // Require SSO refuses every other way in — so the rows below it must say so rather
        // than reading "on" for a door that is shut.
        $ssoOnly = $policy->sso === SsoEnforcement::Required;

        $social = Connection::query()
            ->whereNotNull('organization_id')
            ->whereNotNull('provider')
            ->get(['provider', 'organization_id']);
        $socialProviders = $social->map(static fn (Connection $connection): string => (string) $connection->provider)
            ->filter(static fn (string $provider): bool => $provider !== '')
            ->unique()->sort()->values()->all();
        $socialOrganizations = $social->pluck('organization_id')->unique()->count();

        $enterprise = Connection::query()->whereNull('provider')->get(['id', 'status']);
        $enterpriseActive = $enterprise->filter(fn (Connection $connection): bool => $connection->isActive())->count();

        $policyHref = route('environment.auth-policy');
        $selfService = SelfServiceSignup::enabledFor($environment->get());
        $signupHere = $signup->decidedByEnvironment();
        $shut = 'Refused while Enterprise SSO is required.';

        return $this->page('console/sign-in-methods', Vocabulary::SIGN_IN_METHODS, [
            'help' => HelpProps::for(HelpTopic::SignInMethods),
            'environmentName' => $environment->name() ?? 'this environment',
            'ssoOnly' => $ssoOnly,
            'sections' => [
                [
                    'title' => 'Ways in',
                    'description' => 'What a person can use to prove who they are on your sign-in page.',
                    'rows' => [
                        self::row('password', 'Password', $ssoOnly ? 'off' : 'on',
                            $ssoOnly ? $shut : 'At least '.$policy->minLength.' characters'
                                .($policy->requireBreachCheck ? ', breached passwords refused' : '')
                                .($policy->lockoutThreshold !== null ? ', locked after '.$policy->lockoutThreshold.' failed attempts' : '').'.',
                            'environment', $policyHref, 'Password rules'),
                        self::row('passkeys', 'Passkeys', $ssoOnly ? 'off' : 'on',
                            $ssoOnly ? $shut : 'Always offered — a person adds one from their own account. Face ID, Touch ID, Windows Hello or a security key.',
                            'deployment', null, null, 'CBOX_ID_WEBAUTHN_RP_ID'),
                        self::row('magic-link', 'Magic link', $ssoOnly ? 'off' : 'on',
                            $ssoOnly ? $shut : 'Always offered — a one-time sign-in link by email.',
                            'deployment', null, null),
                        self::row('social', Vocabulary::SOCIAL_LOGIN, $socialProviders === [] ? 'off' : ($ssoOnly ? 'off' : 'on'),
                            $socialProviders === []
                                ? 'None yet. Add Google, GitHub, Microsoft, Apple and others with your own OAuth credentials.'
                                : ($ssoOnly ? $shut : implode(', ', array_map(ucfirst(...), $socialProviders)).' — offered by '.$socialOrganizations.' '.($socialOrganizations === 1 ? 'organization' : 'organizations').'. A provider is set up per organization.'),
                            'organization', route('environment.social-providers'), $socialProviders === [] ? 'Add a provider' : 'Manage'),
                        self::row('enterprise-sso', Vocabulary::ENTERPRISE_SSO, $enterpriseActive > 0 ? 'on' : 'off',
                            ($enterprise->isEmpty()
                                ? 'No connections yet. SAML or OpenID Connect, one per organization.'
                                : $enterpriseActive.' of '.$enterprise->count().' '.($enterprise->count() === 1 ? 'connection' : 'connections').' active.')
                            .' '.match ($policy->sso) {
                                SsoEnforcement::Off => 'Passwords and SSO both work.',
                                SsoEnforcement::Preferred => 'SSO is preferred; passwords still work.',
                                SsoEnforcement::Required => 'SSO is required — every other way in is refused.',
                            },
                            'organization', route('environment.connections'), $enterprise->isEmpty() ? 'Add a connection' : 'Manage'),
                    ],
                ],
                [
                    'title' => 'Second factor',
                    'description' => 'What is asked for after the first step, and from whom.',
                    'rows' => [
                        self::row('mfa', 'Two-factor authentication', match ($policy->mfa) {
                            MfaRequirement::Off => 'off',
                            MfaRequirement::Optional => 'optional',
                            MfaRequirement::Required => 'on',
                        }, match ($policy->mfa) {
                            MfaRequirement::Off => 'Not offered.',
                            MfaRequirement::Optional => 'Optional — people may enrol an authenticator app or a passkey, with recovery codes.',
                            MfaRequirement::Required => 'Required — everyone enrols before they can sign in.',
                        }, 'environment', $policyHref, 'Two-factor rule'),
                        self::row('sms', 'Text-message codes', $smsPolicy->enabled ? 'on' : 'off',
                            $smsPolicy->enabled
                                ? 'Accepted as a second factor in '.count($smsPolicy->allowedCountries).' '.(count($smsPolicy->allowedCountries) === 1 ? 'country' : 'countries').'.'
                                : 'Off. The weakest second factor — turn it on only for people without a smartphone.',
                            'environment', $policyHref.'#sms', 'SMS settings'),
                    ],
                ],
                [
                    'title' => 'Who may get in',
                    'description' => 'Who can create an account, and what is stopped at the door.',
                    'rows' => [
                        self::row('sign-up', 'Self-service sign-up', ($signupHere ? $selfService : $signup->isOpen()) ? 'on' : 'off',
                            ($signupHere ? $selfService : $signup->isOpen())
                                ? 'Anyone can create an account and their own organization.'
                                : 'Invitation only.',
                            $signupHere ? 'environment' : 'deployment',
                            $signupHere ? $policyHref.'#sign-up' : null,
                            $signupHere ? 'Sign-up' : null,
                            $signupHere ? null : 'CBOX_ID_SIGNUP_MODE'),
                        self::row('radar', Vocabulary::RADAR, $radar->mode() === RadarMode::Enforce ? 'on' : 'optional',
                            $radar->mode() === RadarMode::Enforce
                                ? 'Enforcing — risky sign-ins and sign-ups are challenged or blocked.'
                                : 'Monitoring — every attempt is judged and recorded, none is stopped yet.',
                            'environment', route('environment.radar'), 'Radar'),
                        self::row('bot-challenge', 'Bot challenge', $turnstile->configured() ? 'on' : 'off',
                            $turnstile->configured()
                                ? 'A Turnstile challenge is shown when Radar asks for one.'
                                : 'Not configured — Radar can still block, but cannot ask a person to prove they are one.',
                            'deployment', null, null, 'CBOX_ID_TURNSTILE_SITE_KEY'),
                    ],
                ],
                [
                    'title' => 'Sessions',
                    'description' => 'How long a sign-in lasts.',
                    'rows' => [
                        self::row('session', 'Session lifetime', 'on',
                            'A sign-in lasts '.self::minutes(config()->integer('session.lifetime', 120)).' of inactivity.',
                            'deployment', null, null, 'SESSION_LIFETIME'),
                        self::row('tokens', 'Access-token lifetime', 'on',
                            'Set per app, under its Settings tab.',
                            'environment', route('environment.clients'), Vocabulary::APPLICATIONS),
                    ],
                ],
            ],
            'organizationsHref' => $policyHref.'#organizations',
        ]);
    }

    /**
     * One method, as the page draws it.
     *
     * @param  'on'|'off'|'optional'  $state
     * @param  'environment'|'organization'|'deployment'  $decidedBy  whose decision it is — the
     *                                                                environment's own form, each
     *                                                                organization's, or the
     *                                                                deployment's configuration
     * @return array{key: string, label: string, state: string, summary: string, decidedBy: string, href: string|null, hrefLabel: string|null, variable: string|null}
     */
    private static function row(
        string $key,
        string $label,
        string $state,
        string $summary,
        string $decidedBy,
        ?string $href,
        ?string $hrefLabel,
        ?string $variable = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'summary' => $summary,
            'decidedBy' => $decidedBy,
            'href' => $href,
            'hrefLabel' => $hrefLabel,
            'variable' => $variable,
        ];
    }

    private static function minutes(int $minutes): string
    {
        if ($minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);

            return $days.' '.($days === 1 ? 'day' : 'days');
        }

        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours.' '.($hours === 1 ? 'hour' : 'hours');
        }

        return $minutes.' minutes';
    }
}
