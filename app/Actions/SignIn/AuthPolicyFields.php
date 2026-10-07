<?php

declare(strict_types=1);

namespace App\Actions\SignIn;

use App\Platform\Actions\ActionContext;
use App\Platform\Actions\Input\Field;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;

/**
 * The sign-in rules as the management API takes and returns them, and the one rule about
 * them only this app enforces: an organization's override may not loosen the environment's
 * baseline. A helper, not an action.
 */
final class AuthPolicyFields
{
    /**
     * The seven rules, each optional: one left out keeps the value of the level being
     * edited, so a caller changing the MFA requirement need not restate the password rules.
     *
     * @return list<Field>
     */
    public static function fields(): array
    {
        return [
            Field::integer('min_length')->min(8)->max(128)->describe('Minimum password length.'),
            Field::boolean('require_breach_check')->describe('Refuse passwords that appear in known breaches.'),
            Field::integer('max_age_days')->nullable()->min(1)->max(3650)->describe('Days before a password must be changed; null for no limit.'),
            Field::integer('reuse_history')->min(0)->max(24)->describe('How many previous passwords may not be reused.'),
            Field::string('mfa')->oneOf(array_map(static fn (MfaRequirement $case): string => $case->value, MfaRequirement::cases()))->describe('Whether a second factor is off, optional or required.'),
            Field::string('sso')->oneOf(array_map(static fn (SsoEnforcement $case): string => $case->value, SsoEnforcement::cases()))->describe('Whether SSO is off, preferred, or required — `required` refuses every other way in and signs out password sessions.'),
            Field::integer('lockout_threshold')->nullable()->min(3)->max(100)->describe('Failed attempts before an account is locked; null for the deployment default (10 inside 15 minutes unless CBOX_ID_LOCKOUT_THRESHOLD says otherwise).'),
        ];
    }

    /** $base with whatever the caller sent laid over it. */
    public static function policy(ActionContext $context, AuthPolicy $base): AuthPolicy
    {
        $int = static fn (string $key, ?int $current): ?int => $context->has($key)
            ? (is_numeric($context->input[$key]) ? (int) $context->input[$key] : null)
            : $current;

        return new AuthPolicy(
            minLength: $int('min_length', $base->minLength) ?? $base->minLength,
            requireBreachCheck: $context->boolean('require_breach_check', $base->requireBreachCheck),
            maxAgeDays: $int('max_age_days', $base->maxAgeDays),
            reuseHistory: $int('reuse_history', $base->reuseHistory) ?? $base->reuseHistory,
            mfa: $context->has('mfa') ? MfaRequirement::from($context->string('mfa')) : $base->mfa,
            sso: $context->has('sso') ? SsoEnforcement::from($context->string('sso')) : $base->sso,
            lockoutThreshold: $int('lockout_threshold', $base->lockoutThreshold),
        );
    }

    /**
     * What an override tries to LOOSEN, by input name.
     *
     * Every one of these is a value `tightenedWith()` would throw away, so storing one would
     * leave the console showing a number that is not in force anywhere. Refused with a
     * message naming the floor instead — the difference between a console that appears to
     * have saved something and one an administrator can trust.
     *
     * @return array<string, string>
     */
    public static function loosenings(AuthPolicy $policy, AuthPolicy $baseline): array
    {
        $errors = [];

        if ($policy->minLength < $baseline->minLength) {
            $errors['min_length'] = 'Your environment requires at least '.$baseline->minLength.' characters.';
        }

        if ($policy->reuseHistory < $baseline->reuseHistory) {
            $errors['reuse_history'] = 'Your environment blocks reuse of the last '.$baseline->reuseHistory.'.';
        }

        // Null means "no limit", so it is the LOOSEST value either field can hold: an
        // override may shorten the environment's deadline, never remove it.
        if ($baseline->maxAgeDays !== null
            && ($policy->maxAgeDays === null || $policy->maxAgeDays > $baseline->maxAgeDays)) {
            $errors['max_age_days'] = 'Your environment forces a change after '.$baseline->maxAgeDays.' days at the latest.';
        }

        if ($baseline->lockoutThreshold !== null
            && ($policy->lockoutThreshold === null || $policy->lockoutThreshold > $baseline->lockoutThreshold)) {
            $errors['lockout_threshold'] = 'Your environment locks out after '.$baseline->lockoutThreshold.' failed attempts at the most.';
        }

        if ($baseline->requireBreachCheck && ! $policy->requireBreachCheck) {
            $errors['require_breach_check'] = 'Your environment requires the breach check.';
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
     * The rules at one level — `SignInPolicy` in the spec.
     *
     * For an organization `policy` is what is IN FORCE (its override tightened over the
     * baseline), and `override` is what it stored itself: "require SSO" means something
     * different depending on who decided it, and a reader cannot act on it without knowing.
     *
     * @return array<string, mixed>
     */
    public static function present(AuthPolicies $policies, ?string $organizationId): array
    {
        $baseline = $policies->forEnvironment();
        $override = $organizationId === null ? null : $policies->overrideFor($organizationId);

        return [
            'organization_id' => $organizationId,
            'policy' => self::toArray($organizationId === null ? $baseline : $policies->resolve($organizationId)),
            'baseline' => self::toArray($baseline),
            'override' => $override === null ? null : self::toArray($override),
            'inheriting' => $organizationId !== null && $override === null,
        ];
    }

    /**
     * @return array{min_length: int, require_breach_check: bool, max_age_days: int|null, reuse_history: int, mfa: string, sso: string, lockout_threshold: int|null}
     */
    public static function toArray(AuthPolicy $policy): array
    {
        return [
            'min_length' => $policy->minLength,
            'require_breach_check' => $policy->requireBreachCheck,
            'max_age_days' => $policy->maxAgeDays,
            'reuse_history' => $policy->reuseHistory,
            'mfa' => $policy->mfa->value,
            'sso' => $policy->sso->value,
            'lockout_threshold' => $policy->lockoutThreshold,
        ];
    }
}
