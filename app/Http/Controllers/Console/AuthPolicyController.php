<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\SignIn\AuthPolicyFields;
use App\Actions\SignIn\InheritSignInPolicy;
use App\Actions\SignIn\SetSelfServiceSignup;
use App\Actions\SignIn\UpdateSignInPolicy;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\SaveAuthPolicyRequest;
use App\Http\Requests\Console\SaveSelfServiceSignupRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\CurrentEnvironment;
use App\Platform\Help\HelpTopic;
use App\Platform\SelfServiceSignup;
use App\Platform\SignupPolicy;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Response;

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

        $onEnvironmentPlane = $this->onEnvironmentPlane();
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

        return $this->page('console/auth-policy', 'Authentication policy', [
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
            // Both writes, resolved by the server: one controller serves two route names.
            'saveHref' => $this->url('auth-policy.update'),
            'inheritHref' => $this->url('auth-policy.inherit'),
            'selfServiceSignup' => $onEnvironmentPlane ? $this->selfServiceProps() : null,
        ]);
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

        return back()->with('status', $enabled
            ? 'Self-service sign-up is on. People can create an account and their own organization.'
            : 'Self-service sign-up is off. People join by invitation.');
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

        $result = $this->act(UpdateSignInPolicy::class, [
            'organization_id' => $this->onEnvironmentPlane() ? null : $this->organizationId(),
            ...AuthPolicyFields::toArray($policy),
        ], self::FIELDS);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Sign-in rules saved.');
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
        abort_if($this->onEnvironmentPlane(), 403,
            'The environment baseline is what organizations inherit; it cannot itself inherit.');

        $result = $this->act(InheritSignInPolicy::class, ['organization_id' => $this->organizationId()]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Back to the environment defaults.');
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
     * @return list<array{id: string, name: string, overridden: bool, minLength: int, mfa: string, sso: string}>
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
        if (! $this->onEnvironmentPlane()) {
            $name = $this->scope->organizationName();

            return $name === null || $name === '' ? 'this organization' : $name;
        }

        $environment = app(CurrentEnvironment::class)->get();

        return $environment === null ? 'this environment' : $environment->name;
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
            'href' => $this->url('auth-policy.self-service-signup'),
        ];
    }

    private function currentEnvironment(): ?Environment
    {
        $key = app(EnvironmentContext::class)->current()?->environmentKey();

        return $key === null ? null : Environment::query()->find($key);
    }

    private function onEnvironmentPlane(): bool
    {
        return $this->scope->plane() === ConsolePlane::Environment;
    }

    /**
     * The organization being edited. Never called on the environment plane, where this
     * page is about the environment itself.
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
            ['value' => MfaRequirement::Off->value, 'label' => 'Not offered'],
            ['value' => MfaRequirement::Optional->value, 'label' => 'Optional — users may enrol'],
            ['value' => MfaRequirement::Required->value, 'label' => 'Required — users must enrol to sign in'],
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private static function ssoOptions(): array
    {
        return [
            ['value' => SsoEnforcement::Off->value, 'label' => 'Passwords and SSO both available'],
            ['value' => SsoEnforcement::Preferred->value, 'label' => 'Prefer SSO, passwords still work'],
            ['value' => SsoEnforcement::Required->value, 'label' => 'Require SSO — every other way in is refused'],
        ];
    }
}
