<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\SaveAuthPolicyRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\CurrentEnvironment;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
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
 */
final readonly class AuthPolicyController extends ConsoleController
{
    private const PER_PAGE = 25;

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

        return $this->page('console/auth-policy', 'Sign-in rules', [
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
        ]);
    }

    public function update(SaveAuthPolicyRequest $request, AuthPolicies $policies): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $policy = $request->policy();

        if (! $this->onEnvironmentPlane()) {
            $refusals = $this->loosenings($policy, $policies->forEnvironment());

            if ($refusals !== []) {
                return back()->withInput()->withErrors($refusals);
            }
        }

        /*
         * THROUGH THE CONTRACT on both planes, which is what makes the session revocation
         * happen at all: it lives in a decorator around this interface, so a page that
         * reached for the concrete store would tighten the mandate and leave every password
         * session it just invalidated wide open.
         */
        if ($this->onEnvironmentPlane()) {
            $policies->setForEnvironment($policy);
        } else {
            $policies->setForOrganization($this->organizationId(), $policy);
        }

        return back()->with('status', 'Sign-in rules saved.');
    }

    /**
     * Drop the override and go back to inheriting.
     *
     * Organization plane only — the environment baseline is what everything else inherits
     * FROM, so there is nothing above it to fall back to.
     */
    public function inherit(AuthPolicies $policies): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        // Not reachable from the rendered page; refused anyway, because the property that
        // makes that true is the markup, and markup is not an authorization.
        abort_if($this->onEnvironmentPlane(), 403,
            'The environment baseline is what organizations inherit; it cannot itself inherit.');

        $policies->clearForOrganization($this->organizationId());

        return back()->with('status', 'Back to the environment defaults.');
    }

    /**
     * Field errors for anything an override tries to LOOSEN.
     *
     * Every one of these is a value `tightenedWith()` would throw away, so storing one
     * would leave the console showing a number that is not in force anywhere. Refused with
     * a message naming the floor instead — the difference between a console that appears
     * to have saved something and one an administrator can trust.
     *
     * @return array<string, string>
     */
    private function loosenings(AuthPolicy $policy, AuthPolicy $baseline): array
    {
        $errors = [];

        if ($policy->minLength < $baseline->minLength) {
            $errors['minLength'] = 'Your environment requires at least '.$baseline->minLength.' characters.';
        }

        if ($policy->reuseHistory < $baseline->reuseHistory) {
            $errors['reuseHistory'] = 'Your environment blocks reuse of the last '.$baseline->reuseHistory.'.';
        }

        // Null means "no limit", so it is the LOOSEST value either field can hold: an
        // override may shorten the environment's deadline, never remove it.
        if ($baseline->maxAgeDays !== null
            && ($policy->maxAgeDays === null || $policy->maxAgeDays > $baseline->maxAgeDays)) {
            $errors['maxAgeDays'] = 'Your environment forces a change after '.$baseline->maxAgeDays.' days at the latest.';
        }

        if ($baseline->lockoutThreshold !== null
            && ($policy->lockoutThreshold === null || $policy->lockoutThreshold > $baseline->lockoutThreshold)) {
            $errors['lockoutThreshold'] = 'Your environment locks out after '.$baseline->lockoutThreshold.' failed attempts at the most.';
        }

        if ($baseline->requireBreachCheck && ! $policy->requireBreachCheck) {
            $errors['requireBreachCheck'] = 'Your environment requires the breach check.';
        }

        if ($policy->mfa->atLeast($baseline->mfa) !== $policy->mfa) {
            $errors['mfa'] = 'Your environment requires at least "'.$baseline->mfa->value.'".';
        }

        if ($policy->sso->atLeast($baseline->sso) !== $policy->sso) {
            $errors['sso'] = 'Your environment requires at least "'.$baseline->sso->value.'".';
        }

        return $errors;
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
