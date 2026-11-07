<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\Vocabulary;
use App\Platform\CurrentEnvironment;
use App\Platform\Help\HelpTopic;
use App\Platform\Locale\ConsoleLabels;
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
use Illuminate\Support\Facades\Lang;
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
        $organizationName = $onEnvironment ? null : ($this->scope->organizationName() ?? Lang::string('console.this_organization_cab32fc4'));

        $policy = $policies->resolve($organizationId);
        $smsPolicy = $sms->forEnvironment();

        // Require SSO refuses every other way in — so the rows below it must say so rather
        // than reading "on" for a door that is shut.
        $ssoOnly = $policy->sso === SsoEnforcement::Required;
        $shut = __('console.refused_while_enterprise_sso_is_required_d6be9547');

        // Links only to pages THIS console has; an environment-only page is no link at all
        // on an organization console.
        $policyHref = $this->url('auth-policy');
        // The environment's own settings are changeable from here on its console — and on a
        // single-tenant install's organization console, whose administrators are the
        // environment's. On anybody else's they are rows without a link.
        $managesEnvironment = $this->scope->administersEnvironment();
        $methodsHref = $managesEnvironment ? $policyHref.'#sign-in-methods' : null;
        $methodsLabel = $managesEnvironment ? Lang::string('console.sign_in_methods_477be867') : null;

        $selfService = SelfServiceSignup::enabledFor($environment->get());
        $signupHere = $signup->decidedByEnvironment();

        return $this->page('console/sign-in-methods', Lang::string('console.sign_in_methods_477be867'), [
            'help' => HelpProps::for(HelpTopic::SignInMethods),
            'environmentName' => $environment->name() ?? __('console.this_environment_42756031'),
            'organizationName' => $organizationName,
            'ssoOnly' => $ssoOnly,
            // Where "relaxed on Authentication policy" happens — the callout names the page,
            // so it links to it.
            'policyHref' => $policyHref,
            'sections' => [
                [
                    'title' => __('console.ways_in_274cf288'),
                    'description' => __('console.what_a_person_can_use_to_prove_who_they_are_on_your_sig_796b0ff4'),
                    'rows' => [
                        self::row('password', __('console.password_e7cf3ef4'), $ssoOnly ? 'off' : 'on',
                            $ssoOnly ? $shut : __('console.password_policy_minimum', ['count' => $policy->minLength])
                                .($policy->requireBreachCheck ? ' '.__('console.breached_passwords_refused') : '')
                                .($policy->lockoutThreshold !== null ? ' '.__('console.password_policy_lockout', ['count' => $policy->lockoutThreshold]) : ''),
                            $onEnvironment ? 'environment' : 'organization', $policyHref, __('console.password_rules_1960dce0')),
                        self::method('passkeys', __('console.passkeys_c87ccdb4'), $ssoOnly, $shut,
                            enabled: $methods->passkeysEnabled(),
                            deploymentAllows: $methods->deploymentAllowsPasskeys(),
                            on: __('console.offered_a_person_adds_one_from_their_own_account_face_i_8d7b4dc0'),
                            off: __('console.off_for_this_environment_passkeys_people_already_added_035a80ca'),
                            variable: 'CBOX_ID_PASSKEYS_ENABLED',
                            href: $methodsHref, hrefLabel: $methodsLabel),
                        self::method('magic-link', __('console.magic_link_a8a8b124'), $ssoOnly, $shut,
                            enabled: $methods->magicLinkEnabled(),
                            deploymentAllows: $methods->deploymentAllowsMagicLink(),
                            on: __('console.offered_a_one_time_sign_in_link_by_email_d671529d'),
                            off: __('console.off_for_this_environment_links_already_sent_no_longer_w_dacbfa2d'),
                            variable: 'CBOX_ID_MAGIC_LINK_ENABLED',
                            href: $methodsHref, hrefLabel: $methodsLabel),
                        $this->socialRow($providers, $organizationId, $organizationName, $ssoOnly, $shut),
                        $this->enterpriseRow($organizationId, $policy->sso),
                    ],
                ],
                [
                    'title' => __('console.second_factor_e3e01045'),
                    'description' => __('console.what_is_asked_for_after_the_first_step_and_from_whom_31533be6'),
                    'rows' => [
                        self::row('mfa', __('console.two_factor_authentication_b6824cc8'), match ($policy->mfa) {
                            MfaRequirement::Off => 'off',
                            MfaRequirement::Optional => 'optional',
                            MfaRequirement::Required => 'on',
                        }, match ($policy->mfa) {
                            MfaRequirement::Off => __('console.not_offered_67336c47'),
                            MfaRequirement::Optional => __('console.optional_people_may_enrol_an_authenticator_app_or_a_pas_f89148a0'),
                            MfaRequirement::Required => __('console.required_everyone_enrols_before_they_can_sign_in_d9c901be'),
                        }, $onEnvironment ? 'environment' : 'organization', $policyHref, __('console.two_factor_rule_9dfa468a')),
                        self::row('sms', __('console.text_message_codes_9f1561f0'), $smsPolicy->enabled ? 'on' : 'off',
                            $smsPolicy->enabled
                                ? trans_choice('console.sms_allowed_country_count', count($smsPolicy->allowedCountries))
                                : __('console.off_the_weakest_second_factor_turn_it_on_only_for_peopl_3c27fef3'),
                            'environment', $managesEnvironment ? $policyHref.'#sms' : null, $managesEnvironment ? Lang::string('console.sms_settings_e3d8a098') : null),
                    ],
                ],
                [
                    'title' => __('console.who_may_get_in_2c4588de'),
                    'description' => __('console.who_can_create_an_account_and_what_is_stopped_at_the_do_7b1beba9'),
                    'rows' => [
                        self::row('sign-up', __('console.self_service_sign_up_613527ff'), ($signupHere ? $selfService : $signup->isOpen()) ? 'on' : 'off',
                            ($signupHere ? $selfService : $signup->isOpen())
                                ? __('console.anyone_can_create_an_account_and_their_own_organization_51d3b48d')
                                : __('console.invitation_only_b58c63d9'),
                            $signupHere ? 'environment' : 'deployment',
                            $signupHere && $managesEnvironment ? $policyHref.'#sign-up' : null,
                            $signupHere && $managesEnvironment ? Lang::string('console.sign_up_3cc08ebe') : null,
                            $signupHere ? null : 'CBOX_ID_SIGNUP_MODE'),
                        self::row('radar', Vocabulary::RADAR, $radar->mode() === RadarMode::Enforce ? 'on' : 'optional',
                            $radar->mode() === RadarMode::Enforce
                                ? __('console.enforcing_risky_sign_ins_and_sign_ups_are_challenged_or_e40c9e5a')
                                : __('console.monitoring_every_attempt_is_judged_and_recorded_none_is_64218596'),
                            'environment', $onEnvironment ? route('environment.radar') : null, $onEnvironment ? Lang::string('console.radar_524f8327') : null),
                        $this->botChallengeRow($turnstile, $methodsHref, $methodsLabel),
                    ],
                ],
                [
                    'title' => __('console.sessions_6fa3cbf4'),
                    'description' => __('console.how_long_a_sign_in_lasts_96629eff'),
                    'rows' => [
                        $this->sessionRow($methods, $policies->forEnvironment()->sessionIdleMinutes, $policies->forEnvironment()->sessionAbsoluteMinutes, $methodsHref, $methodsLabel),
                        self::row('tokens', __('console.access_token_lifetime_f859ecdd'), 'on',
                            __('console.set_per_app_under_its_settings_tab_c8067284'),
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
                    $offered === [] => __('console.organization_no_social_provider', ['organization' => $organizationName]),
                    $ssoOnly => $shut,
                    default => $own === 0 ? __('console.social_providers_inherited', ['providers' => implode(', ', $names)]) : __('console.social_providers_owned', ['providers' => implode(', ', $names), 'count' => $own, 'organization' => $organizationName]),
                },
                'environment', $href, $offered === [] ? __('console.add_a_provider_f03153fc') : __('console.manage_5a234448'),
                ceiling: __('console.every_organization_inherits_the_environment_s_providers_82f49a7b'));
        }

        $byOrganization = Connection::query()->whereNotNull('organization_id')->whereNotNull('provider')->distinct()->count('organization_id');

        return self::row('social', Vocabulary::SOCIAL_LOGIN, $offered === [] || $ssoOnly ? 'off' : 'on',
            match (true) {
                $offered === [] => __('console.none_for_the_environment_yet_add_google_github_microsof_25c55363'),
                $ssoOnly => $shut,
                default => __('console.social_providers_everywhere', ['providers' => implode(', ', $names)]),
            }.($byOrganization > 0 ? ' '.trans_choice('console.organizations_with_own_providers', $byOrganization) : ''),
            'environment', $href, $offered === [] ? __('console.add_a_provider_f03153fc') : __('console.manage_5a234448'));
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
                ? ($organizationId === null ? __('console.no_connections_yet_saml_or_openid_connect_one_per_organ_8c62e451') : __('console.no_connection_yet_saml_or_openid_connect_192a390f'))
                : __('console.active_sso_connections', ['active' => $active, 'total' => $enterprise->count()]))
            .' '.match ($sso) {
                SsoEnforcement::Off => __('console.passwords_and_sso_both_work_34373f1f'),
                SsoEnforcement::Preferred => __('console.sso_is_preferred_passwords_still_work_ea923a87'),
                SsoEnforcement::Required => __('console.sso_is_required_every_other_way_in_is_refused_06430265'),
            },
            'organization', $this->url('connections'), $enterprise->isEmpty() ? __('console.add_a_connection_e50842ff') : __('console.manage_5a234448'));
    }

    /**
     * @return array<string, mixed>
     */
    private function botChallengeRow(Turnstile $turnstile, ?string $href, ?string $hrefLabel): array
    {
        if (! $turnstile->configured()) {
            return self::row('bot-challenge', __('console.bot_challenge_ed4b037c'), 'off',
                __('console.not_configured_radar_can_still_block_but_cannot_ask_a_p_0a163809'),
                'deployment', null, null, 'CBOX_ID_TURNSTILE_SITE_KEY');
        }

        return self::row('bot-challenge', __('console.bot_challenge_ed4b037c'), $turnstile->enabled() ? 'on' : 'off',
            $turnstile->enabled()
                ? __('console.a_turnstile_challenge_is_shown_when_radar_flags_a_sign_b521aa3c')
                : __('console.off_for_this_environment_a_sign_up_radar_flags_confirms_32622012'),
            'environment', $href, $hrefLabel);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionRow(SignInMethods $methods, ?int $idleChosen, ?int $absoluteChosen, ?string $href, ?string $hrefLabel): array
    {
        $idle = $methods->sessionIdleMinutes();
        $absolute = $methods->sessionAbsoluteMinutes();

        return self::row('session', __('console.session_lifetime_da126ac3'), 'on',
            ($idle > 0 ? __('console.session_with_idle', ['idle' => self::minutes($idle), 'absolute' => self::minutes($absolute)]) : __('console.session_without_idle', ['absolute' => self::minutes($absolute)]))
                .($idleChosen === null && $absoluteChosen === null ? ' '.__('console.deployment_defaults') : ''),
            'environment', $href, $hrefLabel,
            ceiling: $methods->deploymentSessionIdleMinutes() > 0 ? __('console.session_ceiling_with_idle', ['idle' => self::minutes($methods->deploymentSessionIdleMinutes()), 'absolute' => self::minutes($methods->deploymentSessionAbsoluteMinutes())]) : __('console.session_ceiling_without_idle', ['absolute' => self::minutes($methods->deploymentSessionAbsoluteMinutes())]));
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
                __('console.off_for_the_whole_deployment_1386a4da'),
                'deployment', null, null, $variable,
                ceiling: __('console.the_deployment_turned_this_off_and_that_wins_over_any_e_a83ea837'));
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
            'label' => ConsoleLabels::navigation($label),
            'state' => $state,
            'summary' => $summary,
            'decidedBy' => $decidedBy,
            'href' => $href,
            'hrefLabel' => $hrefLabel === null ? null : ConsoleLabels::navigation($hrefLabel),
            'variable' => $variable,
            'ceiling' => $ceiling,
        ];
    }

    private static function minutes(int $minutes): string
    {
        if ($minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);

            return trans_choice('console.duration_day', $days);
        }

        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return trans_choice('console.duration_hour', $hours);
        }

        return trans_choice('console.duration_minute', $minutes);
    }
}
