<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\SignIn\AuthPolicyFields;
use App\Actions\SignIn\InheritSignInPolicy;
use App\Actions\SignIn\SetAdminSignInLink;
use App\Actions\SignIn\SetSelfServiceSignup;
use App\Actions\SignIn\SmsFactorPolicyFields;
use App\Actions\SignIn\UpdateSignInPolicy;
use App\Actions\SignIn\UpdateSmsFactorPolicy;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\SaveAdminSignInLinkRequest;
use App\Http\Requests\Console\SaveAuthPolicyRequest;
use App\Http\Requests\Console\SaveSelfServiceSignupRequest;
use App\Http\Requests\Console\SaveSignInMethodsRequest;
use App\Http\Requests\Console\SaveSmsFactorPolicyRequest;
use App\Platform\AdminSignInLink;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentEnvironment;
use App\Platform\Environments\TenantBaseDomain;
use App\Platform\Help\HelpTopic;
use App\Platform\LockoutDefaults;
use App\Platform\PlaneResolver;
use App\Platform\SelfServiceSignup;
use App\Platform\SignupPolicy;
use App\Platform\Turnstile;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\SignInMethods;
use Cbox\Id\Identity\Contracts\SmsFactorPolicies;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Otp\Sms\CallingCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;
use Inertia\Response;
use Locale;

/**
 * CONSOLE › SIGN-IN RULES — one page, both planes.
 *
 * The two halves of this capability were never a pair, because only one of them existed.
 * The environment plane had a page that wrote the baseline; the per-ORGANIZATION policy —
 * {@see AuthPolicies::setForOrganization()} and its clear — had no writer anywhere in the
 * product, while both read paths enforced it on every sign-in. A rule the platform
 * enforces and nobody can set is worse than no rule: it is a capability the docs describe,
 * the API implies, and the console silently withholds.
 *
 * So the plane decides WHICH LEVEL is being edited, and nothing else:
 *
 *  - environment plane → the baseline every organization inherits, plus the table of what
 *    each one actually ends up with;
 *  - organization plane → that organization's override, which may only ever TIGHTEN the
 *    baseline ({@see AuthPolicy::tightenedWith()}).
 *
 * INHERITANCE IS THE POINT OF THE ORGANIZATION HALF, so it is shown rather than flattened.
 * An administrator reading "Require SSO: off" has to know whether that is their decision
 * or their operator's, because the answer changes what they can do about it — and because
 * the framework merges the two by taking the stricter value, a page that showed only the
 * effective result would present the operator's floor as the tenant's own setting and then
 * refuse to let them lower it, with nothing on screen explaining why.
 *
 * Every write is an ACTION (`App\Actions\SignIn\*`): the same class the management API's
 * `/v1/sign-in/*` runs, so a rule is checked, refused and recorded the same way whichever
 * door changed it. This controller maps the form to the action's input, and its refusals
 * back onto the form's fields.
 */
final readonly class AuthPolicyController extends ConsoleController
{
    private const PER_PAGE = 25;

    /** The action's input names, as this page's form fields. */
    private const FIELDS = [
        'min_length' => 'minLength',
        'require_breach_check' => 'requireBreachCheck',
        'max_age_days' => 'maxAgeDays',
        'reuse_history' => 'reuseHistory',
        'mfa' => 'mfa',
        'sso' => 'sso',
        'lockout_threshold' => 'lockoutThreshold',
    ];

    public function edit(AuthPolicies $policies): Response
    {
        $this->scope->assertMayAdminister();

        $onEnvironmentPlane = $this->editsEnvironment();
        $baseline = $policies->forEnvironment();

        /*
         * The policy the form edits: the baseline on the environment plane, the
         * organization's EFFECTIVE policy on the other.
         *
         * Effective rather than the stored override, deliberately. An organization with no
         * override has no values of its own, and prefilling the form with an empty
         * `AuthPolicy`'s defaults would show a tenant a minimum length of 12 while their
         * environment demanded 16 — and then save the 12 as an override that does nothing.
         */
        $edited = $onEnvironmentPlane ? $baseline : $policies->resolve($this->organizationId());
        $override = $onEnvironmentPlane ? null : $policies->overrideFor($this->organizationId());

        return $this->page('console/auth-policy', __('console.authentication_policy_07e21daf'), [
            'help' => HelpProps::for(HelpTopic::SignInRules),
            'onEnvironmentPlane' => $onEnvironmentPlane,
            'policy' => self::toProps($edited),
            'baseline' => self::toProps($baseline),
            'inheriting' => ! $onEnvironmentPlane && $override === null,
            /*
             * Which FIELDS this organization has actually taken over, so a badge can say so
             * beside each one. Compared against the baseline rather than reported as "there
             * is an override": an override that restates the baseline in six fields and
             * tightens the seventh should read as one decision, not seven.
             */
            'overridden' => $override === null ? [] : array_keys(array_filter([
                'minLength' => $override->minLength !== $baseline->minLength,
                'requireBreachCheck' => $override->requireBreachCheck !== $baseline->requireBreachCheck,
                'maxAgeDays' => $override->maxAgeDays !== $baseline->maxAgeDays,
                'reuseHistory' => $override->reuseHistory !== $baseline->reuseHistory,
                'mfa' => $override->mfa !== $baseline->mfa,
                'sso' => $override->sso !== $baseline->sso,
                'lockoutThreshold' => $override->lockoutThreshold !== $baseline->lockoutThreshold,
            ])),
            'scopeName' => $this->scopeName(),
            // What an EMPTY lockout threshold means: the deployment's default, which is on
            // unless the operator switched it off — never "no lockout" on its own.
            'lockoutDefault' => LockoutDefaults::props(),
            /*
             * WHETHER PASSWORDS WORK TODAY — the one fact the "this will sign people out"
             * warning needs that the browser cannot derive.
             *
             * Turning the mandate on ends every password session it governs, and the person
             * most likely to be holding one is the administrator reading the page. But an
             * organization already covered by an environment-wide mandate loses nothing by
             * restating it, and asking "are you sure you want to end every session" about a
             * change that ends none is how confirmations become reflexes.
             */
            'passwordsCurrentlyWork' => $onEnvironmentPlane
                ? $policies->resolve()->sso->allowsPasswordLogin()
                : $policies->resolve($this->organizationId())->sso->allowsPasswordLogin(),
            'mfaOptions' => self::mfaOptions(),
            'ssoOptions' => self::ssoOptions(),
            'organizations' => $onEnvironmentPlane ? $this->organizationRows($policies) : null,
            'organizationsPagination' => $onEnvironmentPlane
                ? PaginationProps::from($this->organizationPage())
                : null,
            // Both writes, resolved by the server: one controller serves both consoles' pages
            // and an organization's Authentication policy tab. The environment baseline inherits from
            // nothing, so it has nowhere to send "inherit".
            'saveHref' => $this->scope->plane() === ConsolePlane::Environment && ! $onEnvironmentPlane
                ? route('environment.organizations.policy.update')
                : $this->url('auth-policy.update'),
            'inheritHref' => $onEnvironmentPlane ? null : $this->url('auth-policy.inherit'),
            /*
             * THE ENVIRONMENT'S OWN SETTINGS — on its console, and on a single-tenant
             * install's organization console, whose administrators are the environment's
             * ({@see ConsoleScope::administersEnvironment()}). A customer's console never.
             */
            'environmentName' => $this->environmentName(),
            'selfServiceSignup' => $this->managesEnvironment() ? $this->selfServiceProps() : null,
            'adminSignInLink' => $this->managesEnvironment() ? $this->adminSignInLinkProps() : null,
            'smsFactor' => $this->managesEnvironment() ? $this->smsFactorProps() : null,
            'signInMethods' => $this->managesEnvironment() ? $this->signInMethodsProps($baseline) : null,
        ]);
    }

    /**
     * The environment's sign-in methods and session lengths as the page draws them, with the
     * deployment's ceiling beside each — so a switch the deployment holds off is drawn off
     * and says why, and a length field says the most it may be.
     *
     * @return array{passkeys: bool, magicLink: bool, botChallenge: bool, sessionIdleMinutes: string, sessionAbsoluteMinutes: string, deployment: array{passkeys: bool, magicLink: bool, botChallenge: bool, sessionIdleMinutes: int, sessionAbsoluteMinutes: int}, inForce: array{sessionIdleMinutes: int, sessionAbsoluteMinutes: int}, href: string}
     */
    private function signInMethodsProps(AuthPolicy $baseline): array
    {
        $methods = app(SignInMethods::class);

        return [
            'passkeys' => $baseline->passkeys,
            'magicLink' => $baseline->magicLink,
            'botChallenge' => $baseline->botChallenge,
            // Strings, empty for "the deployment's", like the other optional numbers here.
            'sessionIdleMinutes' => $baseline->sessionIdleMinutes === null ? '' : (string) $baseline->sessionIdleMinutes,
            'sessionAbsoluteMinutes' => $baseline->sessionAbsoluteMinutes === null ? '' : (string) $baseline->sessionAbsoluteMinutes,
            'deployment' => [
                'passkeys' => $methods->deploymentAllowsPasskeys(),
                'magicLink' => $methods->deploymentAllowsMagicLink(),
                'botChallenge' => app(Turnstile::class)->configured(),
                'sessionIdleMinutes' => $methods->deploymentSessionIdleMinutes(),
                'sessionAbsoluteMinutes' => $methods->deploymentSessionAbsoluteMinutes(),
            ],
            'inForce' => [
                'sessionIdleMinutes' => $methods->sessionIdleMinutes(),
                'sessionAbsoluteMinutes' => $methods->sessionAbsoluteMinutes(),
            ],
            'href' => $this->url('auth-policy.methods'),
        ];
    }

    /**
     * `PUT /admin/sign-in-rules/self-service-signup` — the environment's own "let people
     * sign themselves up" switch ({@see SelfServiceSignup}).
     *
     * Environment plane only. It decides who may create an account in the whole
     * environment — every organization in it included — so an organization administrator
     * has no say in it, and its arrival from the other plane is refused rather than
     * quietly applied to the environment anyway.
     *
     * The write is the ACTION ({@see SetSelfServiceSignup}) the management API runs, which
     * records it on the workspace's trail and refuses when there is no workspace to record
     * it on — before the switch moves.
     */
    public function selfServiceSignup(SaveSelfServiceSignupRequest $request): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $enabled = $request->enabled();

        $result = $this->act(SetSelfServiceSignup::class, ['enabled' => $enabled], [], 'enabled');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return back()->with('status', $enabled ? __('console.self_service_sign_up_is_on_people_can_create_an_account_af9f74f0') : __('console.self_service_sign_up_is_off_people_join_by_invitation_3a61a734'));
    }

    /**
     * `PUT /admin/sign-in-rules/admin-link` — whether the environment's sign-in page carries
     * the link for administrators ({@see AdminSignInLink}). Environment plane only, through
     * the action the management API runs ({@see SetAdminSignInLink}).
     */
    public function adminLink(SaveAdminSignInLinkRequest $request): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $enabled = $request->enabled();

        $result = $this->act(SetAdminSignInLink::class, ['enabled' => $enabled], [], 'enabled');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return back()->with('status', $enabled ? __('console.the_administrator_link_is_shown_on_the_sign_in_page_72788d7a') : __('console.the_administrator_link_is_hidden_from_the_sign_in_page_08a5764f'));
    }

    /**
     * `PUT /admin/sign-in-rules/sms` — text-message codes as a second factor, through the
     * action the management API runs ({@see UpdateSmsFactorPolicy}). Environment plane only,
     * like self-service sign-up: which countries the environment texts is a cost and fraud
     * decision for the environment, not for one organization in it.
     */
    public function sms(SaveSmsFactorPolicyRequest $request): RedirectResponse
    {
        $this->scope->assertAdministersEnvironment();

        $input = $request->policyInput();

        $result = $this->act(UpdateSmsFactorPolicy::class, $input, [
            'enabled' => 'enabled',
            'allowed_countries' => 'allowedCountries',
            'privileged_need_stronger_factor' => 'privilegedNeedStrongerFactor',
        ], 'allowedCountries', asEnvironment: true);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return back()->with('status', $input['enabled'] ? __('console.sms_enabled_country_count', ['count' => count($input['allowed_countries'])]) : __('console.text_message_codes_are_off_1bc7fc7a'));
    }

    /**
     * Save the rules at this plane's level, through the ACTION the management API runs
     * ({@see UpdateSignInPolicy}): the baseline here on the environment plane, this
     * organization's override on the other — where a loosening is refused field by field.
     *
     * The action writes through the {@see AuthPolicies} contract, which is what makes the
     * session revocation happen at all: it lives in a decorator around that interface.
     */
    public function update(SaveAuthPolicyRequest $request): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $policy = $request->policy();

        // The password, MFA, SSO and lockout rules only: the sign-in methods and session
        // lengths are a form of their own ({@see methods()}), and leaving them out here
        // keeps whatever is on file rather than resetting it to this form's defaults.
        $result = $this->act(UpdateSignInPolicy::class, [
            'organization_id' => $this->editsEnvironment() ? null : $this->organizationId(),
            ...AuthPolicyFields::organizationArray($policy),
        ], self::FIELDS);

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.authentication_policy_saved_e2467bd0'));
    }

    /**
     * `PUT /admin/sign-in-rules/methods` — passkeys, magic links, the bot challenge and how
     * long sessions last, for the whole environment, through the same action the management
     * API runs ({@see UpdateSignInPolicy}), which refuses a length past the deployment's
     * ceiling with the ceiling named.
     *
     * Environment plane only: these are decided where the organization is usually not known
     * yet, so they cannot be an organization's.
     */
    public function methods(SaveSignInMethodsRequest $request): RedirectResponse
    {
        $this->scope->assertAdministersEnvironment();

        $result = $this->act(UpdateSignInPolicy::class, $request->methodsInput(), [
            'passkeys' => 'passkeys',
            'magic_link' => 'magicLink',
            'bot_challenge' => 'botChallenge',
            'session_idle_minutes' => 'sessionIdleMinutes',
            'session_absolute_minutes' => 'sessionAbsoluteMinutes',
        ], 'sessionAbsoluteMinutes', asEnvironment: true);

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.sign_in_methods_and_sessions_saved_49b79196'));
    }

    /**
     * Drop the override and go back to inheriting.
     *
     * Organization plane only — the environment baseline is what everything else inherits
     * FROM, so there is nothing above it to fall back to.
     */
    public function inherit(): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        // Not reachable from the rendered page; refused anyway, because the property that
        // makes that true is the markup, and markup is not an authorization.
        abort_if($this->editsEnvironment(), 403,
            __('console.the_environment_baseline_is_what_organizatio_21fbaea3'));

        $result = $this->act(InheritSignInPolicy::class, ['organization_id' => $this->organizationId()]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.back_to_the_environment_defaults_973ef834'));
    }

    /**
     * What each organization in the environment ends up with — the environment plane's
     * answer to "did my baseline actually land".
     *
     * Paginated, and the overrides are read in ONE query for the page rather than one per
     * row: the previous page walked every organization in the environment unpaginated and
     * asked `overrideFor()` for each, which is the N+1 the batch reader on that contract
     * was added to remove.
     *
     * @return list<array{id: string, name: string, href: string, overridden: bool, minLength: int, mfa: string, sso: string}>
     */
    private function organizationRows(AuthPolicies $policies): array
    {
        $page = $this->organizationPage();

        $ids = array_values(array_map(
            static fn (Organization $organization): string => $organization->id,
            $page->items(),
        ));

        $overrides = $policies->overridesFor($ids);
        $baseline = $policies->forEnvironment();

        return array_values(array_map(function (Organization $organization) use ($overrides, $baseline): array {
            $effective = isset($overrides[$organization->id])
                ? $baseline->tightenedWith($overrides[$organization->id])
                : $baseline;

            return [
                'id' => $organization->id,
                'name' => $organization->name,
                // Its own Authentication policy tab, where the override is edited.
                'href' => route('environment.organizations.policy', ['organization' => $organization->id]),
                'overridden' => isset($overrides[$organization->id]),
                'minLength' => $effective->minLength,
                'mfa' => ucfirst($effective->mfa->value),
                'sso' => ucfirst($effective->sso->value),
            ];
        }, $page->items()));
    }

    /** @return LengthAwarePaginator<int, Organization> */
    private function organizationPage(): LengthAwarePaginator
    {
        return Organization::query()->orderBy('name')->paginate(self::PER_PAGE, ['id', 'name']);
    }

    /**
     * What this page is about, in the words the administrator would use: the environment
     * on one plane, the organization on the other.
     *
     * Named rather than left as "this organization" wherever it can be, because the copy
     * that carries the consequence — "this will sign people out of …" — is only useful if
     * it says WHOSE people.
     */
    private function scopeName(): string
    {
        if (! $this->editsEnvironment()) {
            $name = $this->scope->organizationName();

            return $name === null || $name === '' ? __('console.this_organization_cab32fc4') : $name;
        }

        $environment = app(CurrentEnvironment::class)->get();

        return $environment === null ? __('console.this_environment_42756031') : $environment->name;
    }

    /**
     * The switch as the page draws it.
     *
     * `decidedHere` is false on a single-tenant install, where sign-up follows the
     * deployment's `CBOX_ID_SIGNUP_MODE` and the switch would change nothing — so the page
     * says what does decide it instead of drawing a control that is not connected to
     * anything.
     *
     * @return array{decidedHere: bool, enabled: bool, open: bool, mode: string, href: string}
     */
    private function selfServiceProps(): array
    {
        $policy = app(SignupPolicy::class);

        return [
            'decidedHere' => $policy->decidedByEnvironment(),
            'enabled' => SelfServiceSignup::enabledFor($this->currentEnvironment()),
            'open' => $policy->isOpen(),
            'mode' => $policy->mode(),
            // The switch only exists where the environment decides; elsewhere the page says
            // what does, and there is nothing to post.
            'href' => $policy->decidedByEnvironment() && Route::has($this->routeName('auth-policy.self-service-signup'))
                ? $this->url('auth-policy.self-service-signup')
                : '',
        ];
    }

    /**
     * The link for administrators on the sign-in page, or null where there is no such link
     * to switch: a single-tenant install has no console on another host to point at.
     *
     * `enabled` is what the environment's MAIN address shows right now: the choice when one
     * was made, else the default for that address (on under a tenant base domain, off on a
     * custom domain). `chosen` is false while the default still decides.
     *
     * @return array{enabled: bool, chosen: bool, baseDomain: ?string, href: string}|null
     */
    private function adminSignInLinkProps(): ?array
    {
        if (! app(PlaneResolver::class)->isMultiTenant() || ! Route::has($this->routeName('auth-policy.admin-link'))) {
            return null;
        }

        $environment = $this->currentEnvironment();
        $host = app(IssuerResolver::class)->canonicalHost() ?? '';

        return [
            'enabled' => AdminSignInLink::shownFor($environment, $host),
            'chosen' => AdminSignInLink::chosenFor($environment) !== null,
            'baseDomain' => TenantBaseDomain::canonical(),
            'href' => $this->url('auth-policy.admin-link'),
        ];
    }

    /**
     * The SMS policy as the page draws it, with every country a number can be placed in —
     * named in English, the console's language — so the picker offers only what the
     * platform can actually text. `deploymentCountries` is the operator's ceiling, shown so
     * the page does not promise a country the deployment will refuse.
     *
     * @return array{enabled: bool, allowedCountries: list<string>, privilegedNeedStrongerFactor: bool, deploymentCountries: list<string>, countries: list<array{value: string, label: string}>, href: string}
     */
    private function smsFactorProps(): array
    {
        $policy = app(SmsFactorPolicies::class)->forEnvironment();

        $countries = array_map(static fn (string $code): array => [
            'value' => $code,
            'label' => self::countryName($code),
        ], CallingCodes::countries());

        usort($countries, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return [
            'enabled' => $policy->enabled,
            'allowedCountries' => $policy->allowedCountries,
            'privilegedNeedStrongerFactor' => $policy->privilegedNeedStrongerFactor,
            'deploymentCountries' => SmsFactorPolicyFields::deploymentCountries(),
            'countries' => $countries,
            'href' => $this->url('auth-policy.sms'),
        ];
    }

    private static function countryName(string $code): string
    {
        $name = Locale::getDisplayRegion('-'.$code, 'en');

        return is_string($name) && $name !== '' && $name !== $code ? $name : $code;
    }

    private function currentEnvironment(): ?Environment
    {
        $key = app(EnvironmentContext::class)->current()?->environmentKey();

        return $key === null ? null : Environment::query()->find($key);
    }

    /**
     * Whether this page also draws the ENVIRONMENT's own settings — methods and sessions,
     * text-message codes, self-service sign-up: on the environment's own page, and on a
     * single-tenant install's organization console.
     */
    private function managesEnvironment(): bool
    {
        return $this->editsEnvironment()
            || ($this->scope->plane() === ConsolePlane::Organization && $this->scope->administersEnvironment());
    }

    private function environmentName(): string
    {
        $environment = app(CurrentEnvironment::class)->get();

        return $environment === null ? __('console.this_environment_42756031') : $environment->name;
    }

    /**
     * Whether this page edits the ENVIRONMENT's baseline rather than one organization's
     * override: the environment console's own Sign-in rules page. An organization's Policy
     * tab on the same console (`/admin/organizations/{organization}/policy`) edits that
     * organization's, exactly as its own console does.
     */
    private function editsEnvironment(): bool
    {
        return $this->scope->plane() === ConsolePlane::Environment && $this->scope->organizationId() === null;
    }

    /**
     * The organization being edited. Never called on the environment's own page, which is
     * about the environment itself.
     */
    private function organizationId(): string
    {
        return $this->scope->requireOrganizationId();
    }

    /**
     * @return array{minLength: int, requireBreachCheck: bool, maxAgeDays: string, reuseHistory: int, mfa: string, sso: string, lockoutThreshold: string}
     */
    private static function toProps(AuthPolicy $policy): array
    {
        return [
            'minLength' => $policy->minLength,
            'requireBreachCheck' => $policy->requireBreachCheck,
            // The two nullable numbers cross as STRINGS, empty for "no limit". They are
            // number inputs whose empty state is the meaningful one, and a null round-trips
            // through a controlled input as the string "null".
            'maxAgeDays' => $policy->maxAgeDays === null ? '' : (string) $policy->maxAgeDays,
            'reuseHistory' => $policy->reuseHistory,
            'mfa' => $policy->mfa->value,
            'sso' => $policy->sso->value,
            'lockoutThreshold' => $policy->lockoutThreshold === null ? '' : (string) $policy->lockoutThreshold,
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private static function mfaOptions(): array
    {
        return [
            ['value' => MfaRequirement::Off->value, 'label' => __('console.not_offered_95a607c4')],
            ['value' => MfaRequirement::Optional->value, 'label' => __('console.optional_users_may_enrol_b0a65554')],
            ['value' => MfaRequirement::Required->value, 'label' => __('console.required_users_must_enrol_to_sign_in_c231ab48')],
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private static function ssoOptions(): array
    {
        return [
            ['value' => SsoEnforcement::Off->value, 'label' => __('console.passwords_and_sso_both_available_9f2d4e45')],
            ['value' => SsoEnforcement::Preferred->value, 'label' => __('console.prefer_sso_passwords_still_work_c7e6f315')],
            ['value' => SsoEnforcement::Required->value, 'label' => __('console.require_sso_every_other_way_in_is_refused_15214c5c')],
        ];
    }
}
