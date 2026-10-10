<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\Vocabulary;
use App\Platform\CurrentEnvironment;
use App\Platform\Help\HelpTopic;
use App\Platform\Radar\Enums\RadarMode;
use App\Platform\Radar\RadarPolicy;
use App\Platform\SelfServiceSignup;
use App\Platform\SignupPolicy;
use App\Platform\Turnstile;
use Cbox\Id\Federation\Contracts\SignInProviders;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\SignInMethods;
use Cbox\Id\Identity\Contracts\SmsFactorPolicies;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Inertia\Response;

/**
 * AUTHENTICATION › SIGN-IN METHODS — every way in on one page: whether it is on, what it is
 * set to, and where it is changed.
 *
 * WHY A PAGE THAT CHANGES NOTHING. The question a new administrator asks first — "where do
 * I turn passkeys on?", "is magic link on?", "how long do sessions last?" — had no single
 * answer. WorkOS answers it with an Authentication › Methods page, Clerk with "User &
 * authentication"; this is ours.
 *
 * READ-ONLY ON PURPOSE. Each row links to the page whose form changes it, so there is still
 * exactly one writer for each setting (and every write is still the Action behind that
 * page).
 *
 * THREE OWNERS, AND THE PAGE SAYS WHICH. Passkeys, magic links, the bot challenge and
 * session lengths used to be the deployment's alone, and this page said so; they are now
 * the ENVIRONMENT's, on Authentication policy, under the deployment's ceiling. Where the
 * deployment holds a method off, the row says that the deployment wins and names the
 * variable — rather than showing an environment switch that would do nothing. Social login
 * is the environment's too now (every organization inherits its providers); SSO
 * connections stay per organization.
 *
 * BOTH CONSOLES. On the environment console it reports the environment. On an organization
 * console — the whole administration on a single-tenant install — it reports what THAT
 * organization's people get: its effective rules, the social buttons its page shows after
 * inheritance, its own SSO connection. On a single-tenant install that console's
 * administrators are the environment's too, so the environment's rows link to the panels that
 * change them; on any other organization console they say whose they are and offer no link,
 * rather than one that would answer 403.
 */
final readonly class SignInMethodsController extends ConsoleController
{
    public function __invoke(
        AuthPolicies $policies,
        SignInMethods $methods,
        SignInProviders $providers,
        SmsFactorPolicies $sms,
        RadarPolicy $radar,
        SignupPolicy $signup,
        Turnstile $turnstile,
        CurrentEnvironment $environment,
    ): Response {
        $onEnvironment = $this->scope->plane() === ConsolePlane::Environment;

        if ($onEnvironment) {
            $this->scope->assertMayAdministerEnvironment();
        } else {
            $this->scope->assertMayAdminister();
        }

        $organizationId = $onEnvironment ? null : $this->scope->requireOrganizationId();
        $organizationName = $onEnvironment ? null : ($this->scope->organizationName() ?? 'this organization');

        $policy = $policies->resolve($organizationId);
        $smsPolicy = $sms->forEnvironment();

        // Require SSO refuses every other way in — so the rows below it must say so rather
        // than reading "on" for a door that is shut.
        $ssoOnly = $policy->sso === SsoEnforcement::Required;
        $shut = 'Refused while Enterprise SSO is required.';

        // Links only to pages THIS console has; an environment-only page is no link at all
        // on an organization console.
        $policyHref = $this->url('auth-policy');
        // The environment's own settings are changeable from here on its console — and on a
        // single-tenant install's organization console, whose administrators are the
        // environment's. On anybody else's they are rows without a link.
        $managesEnvironment = $this->scope->administersEnvironment();
        $methodsHref = $managesEnvironment ? $policyHref.'#sign-in-methods' : null;
        $methodsLabel = $managesEnvironment ? 'Sign-in methods' : null;

        $selfService = SelfServiceSignup::enabledFor($environment->get());
        $signupHere = $signup->decidedByEnvironment();

        return $this->page('console/sign-in-methods', Vocabulary::SIGN_IN_METHODS, [
            'help' => HelpProps::for(HelpTopic::SignInMethods),
            'environmentName' => $environment->name() ?? 'this environment',
            'organizationName' => $organizationName,
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
                            $onEnvironment ? 'environment' : 'organization', $policyHref, 'Password rules'),
                        self::method('passkeys', 'Passkeys', $ssoOnly, $shut,
                            enabled: $methods->passkeysEnabled(),
                            deploymentAllows: $methods->deploymentAllowsPasskeys(),
                            on: 'Offered — a person adds one from their own account. Face ID, Touch ID, Windows Hello or a security key.',
                            off: 'Off for this environment. Passkeys people already added are kept, and work again if you turn this back on.',
                            variable: 'CBOX_ID_PASSKEYS_ENABLED',
                            href: $methodsHref, hrefLabel: $methodsLabel),
                        self::method('magic-link', 'Magic link', $ssoOnly, $shut,
                            enabled: $methods->magicLinkEnabled(),
                            deploymentAllows: $methods->deploymentAllowsMagicLink(),
                            on: 'Offered — a one-time sign-in link by email.',
                            off: 'Off for this environment. Links already sent no longer work.',
                            variable: 'CBOX_ID_MAGIC_LINK_ENABLED',
                            href: $methodsHref, hrefLabel: $methodsLabel),
                        $this->socialRow($providers, $organizationId, $organizationName, $ssoOnly, $shut),
                        $this->enterpriseRow($organizationId, $policy->sso),
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
                        }, $onEnvironment ? 'environment' : 'organization', $policyHref, 'Two-factor rule'),
                        self::row('sms', 'Text-message codes', $smsPolicy->enabled ? 'on' : 'off',
                            $smsPolicy->enabled
                                ? 'Accepted as a second factor in '.count($smsPolicy->allowedCountries).' '.(count($smsPolicy->allowedCountries) === 1 ? 'country' : 'countries').'.'
                                : 'Off. The weakest second factor — turn it on only for people without a smartphone.',
                            'environment', $managesEnvironment ? $policyHref.'#sms' : null, $managesEnvironment ? 'SMS settings' : null),
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
                            $signupHere && $managesEnvironment ? $policyHref.'#sign-up' : null,
                            $signupHere && $managesEnvironment ? 'Sign-up' : null,
                            $signupHere ? null : 'CBOX_ID_SIGNUP_MODE'),
                        self::row('radar', Vocabulary::RADAR, $radar->mode() === RadarMode::Enforce ? 'on' : 'optional',
                            $radar->mode() === RadarMode::Enforce
                                ? 'Enforcing — risky sign-ins and sign-ups are challenged or blocked.'
                                : 'Monitoring — every attempt is judged and recorded, none is stopped yet.',
                            'environment', $onEnvironment ? route('environment.radar') : null, $onEnvironment ? 'Radar' : null),
                        $this->botChallengeRow($turnstile, $methodsHref, $methodsLabel),
                    ],
                ],
                [
                    'title' => 'Sessions',
                    'description' => 'How long a sign-in lasts.',
                    'rows' => [
                        $this->sessionRow($methods, $policies->forEnvironment()->sessionIdleMinutes, $policies->forEnvironment()->sessionAbsoluteMinutes, $methodsHref, $methodsLabel),
                        self::row('tokens', 'Access-token lifetime', 'on',
                            'Set per app, under its Settings tab.',
                            'environment', $this->url('clients'), Vocabulary::APPLICATIONS),
                    ],
                ],
            ],
            'organizationsHref' => $onEnvironment ? $policyHref.'#organizations' : null,
        ]);
    }

    /**
     * Social login, as THIS console's sign-in page offers it: the environment's providers on
     * the environment console, and on an organization's the buttons its page shows after
     * inheritance — which may be its own in the environment's place.
     *
     * @return array<string, mixed>
     */
    private function socialRow(SignInProviders $providers, ?string $organizationId, ?string $organizationName, bool $ssoOnly, string $shut): array
    {
        $offered = $providers->offeredTo($organizationId);
        $names = array_map(static fn (Connection $connection): string => $connection->name, $offered);
        $href = $this->url('social-providers');

        if ($organizationId !== null) {
            $own = count(array_filter($offered, static fn (Connection $connection): bool => $connection->organization_id !== null));

            return self::row('social', Vocabulary::SOCIAL_LOGIN, $offered === [] || $ssoOnly ? 'off' : 'on',
                match (true) {
                    $offered === [] => 'None on '.$organizationName.'’s sign-in page.',
                    $ssoOnly => $shut,
                    default => implode(', ', $names).' — '.($own === 0 ? 'all from the environment.' : $own.' of them '.$organizationName.'’s own, the rest from the environment.'),
                },
                'environment', $href, $offered === [] ? 'Add a provider' : 'Manage',
                ceiling: 'Every organization inherits the environment’s providers; it can bring its own or turn one off.');
        }

        $byOrganization = Connection::query()->whereNotNull('organization_id')->whereNotNull('provider')->distinct()->count('organization_id');

        return self::row('social', Vocabulary::SOCIAL_LOGIN, $offered === [] || $ssoOnly ? 'off' : 'on',
            match (true) {
                $offered === [] => 'None for the environment yet. Add Google, GitHub, Microsoft, Apple and others with your own OAuth credentials, and every organization offers them.',
                $ssoOnly => $shut,
                default => implode(', ', $names).' — offered on every sign-in page in this environment.',
            }.($byOrganization > 0 ? ' '.$byOrganization.' '.($byOrganization === 1 ? 'organization has' : 'organizations have').' providers of their own.' : ''),
            'environment', $href, $offered === [] ? 'Add a provider' : 'Manage');
    }

    /**
     * @return array<string, mixed>
     */
    private function enterpriseRow(?string $organizationId, SsoEnforcement $sso): array
    {
        $enterprise = Connection::query()
            ->whereNull('provider')
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->get(['id', 'status']);
        $active = $enterprise->filter(fn (Connection $connection): bool => $connection->isActive())->count();

        return self::row('enterprise-sso', Vocabulary::ENTERPRISE_SSO, $active > 0 ? 'on' : 'off',
            ($enterprise->isEmpty()
                ? ($organizationId === null ? 'No connections yet. SAML or OpenID Connect, one per organization.' : 'No connection yet. SAML or OpenID Connect.')
                : $active.' of '.$enterprise->count().' '.($enterprise->count() === 1 ? 'connection' : 'connections').' active.')
            .' '.match ($sso) {
                SsoEnforcement::Off => 'Passwords and SSO both work.',
                SsoEnforcement::Preferred => 'SSO is preferred; passwords still work.',
                SsoEnforcement::Required => 'SSO is required — every other way in is refused.',
            },
            'organization', $this->url('connections'), $enterprise->isEmpty() ? 'Add a connection' : 'Manage');
    }

    /**
     * @return array<string, mixed>
     */
    private function botChallengeRow(Turnstile $turnstile, ?string $href, ?string $hrefLabel): array
    {
        if (! $turnstile->configured()) {
            return self::row('bot-challenge', 'Bot challenge', 'off',
                'Not configured — Radar can still block, but cannot ask a person to prove they are one. A flagged sign-up confirms its email address instead.',
                'deployment', null, null, 'CBOX_ID_TURNSTILE_SITE_KEY');
        }

        return self::row('bot-challenge', 'Bot challenge', $turnstile->enabled() ? 'on' : 'off',
            $turnstile->enabled()
                ? 'A Turnstile challenge is shown when Radar flags a sign-up.'
                : 'Off for this environment — a sign-up Radar flags confirms its email address instead.',
            'environment', $href, $hrefLabel);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionRow(SignInMethods $methods, ?int $idleChosen, ?int $absoluteChosen, ?string $href, ?string $hrefLabel): array
    {
        $idle = $methods->sessionIdleMinutes();
        $absolute = $methods->sessionAbsoluteMinutes();

        return self::row('session', 'Session lifetime', 'on',
            ($idle > 0 ? 'A sign-in ends after '.self::minutes($idle).' without activity, and ' : 'A sign-in ends ')
                .'after '.self::minutes($absolute).' at most.'
                .($idleChosen === null && $absoluteChosen === null ? ' These are the deployment’s defaults.' : ''),
            'environment', $href, $hrefLabel,
            ceiling: 'The deployment allows at most '
                .($methods->deploymentSessionIdleMinutes() > 0 ? self::minutes($methods->deploymentSessionIdleMinutes()).' idle and ' : '')
                .self::minutes($methods->deploymentSessionAbsoluteMinutes()).' in all (CBOX_ID_SESSION_IDLE_MINUTES, CBOX_ID_SESSION_TTL_MINUTES).');
    }

    /**
     * A sign-in method the environment switches under the deployment's ceiling: the
     * environment's when the deployment offers it, the deployment's — named, and said to
     * win — when it does not.
     *
     * @return array<string, mixed>
     */
    private static function method(
        string $key,
        string $label,
        bool $ssoOnly,
        string $shut,
        bool $enabled,
        bool $deploymentAllows,
        string $on,
        string $off,
        string $variable,
        ?string $href,
        ?string $hrefLabel,
    ): array {
        if (! $deploymentAllows) {
            return self::row($key, $label, 'off',
                'Off for the whole deployment.',
                'deployment', null, null, $variable,
                ceiling: 'The deployment turned this off, and that wins over any environment’s setting.');
        }

        return self::row($key, $label, $enabled && ! $ssoOnly ? 'on' : 'off',
            $ssoOnly && $enabled ? $shut : ($enabled ? $on : $off),
            'environment', $href, $hrefLabel);
    }

    /**
     * One method, as the page draws it.
     *
     * @param  'on'|'off'|'optional'  $state
     * @param  'environment'|'organization'|'deployment'  $decidedBy  whose decision it is — the
     *                                                                environment's own form, each
     *                                                                organization's, or the
     *                                                                deployment's configuration
     * @param  string|null  $ceiling  what bounds the setting from above, when something does
     * @return array{key: string, label: string, state: string, summary: string, decidedBy: string, href: string|null, hrefLabel: string|null, variable: string|null, ceiling: string|null}
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
        ?string $ceiling = null,
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
            'ceiling' => $ceiling,
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
