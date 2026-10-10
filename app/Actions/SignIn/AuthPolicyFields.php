<?php

declare(strict_types=1);

namespace App\Actions\SignIn;

use App\Platform\Actions\ActionContext;
use App\Platform\Actions\Input\Field;
use App\Platform\Turnstile;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\SignInMethods;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;

/**
 * The sign-in rules as the management API takes and returns them, and the one rule about
 * them only this app enforces: an organization's override may not loosen the environment's
 * baseline. A helper, not an action.
 *
 * TWO KINDS OF FIELD. The password, MFA, SSO and lockout rules exist at both levels, an
 * organization tightening its environment's. The sign-in METHODS and SESSION LENGTHS —
 * passkeys, magic links, the bot challenge, idle and absolute session lengths — are the
 * environment's alone ({@see AuthPolicy::environmentWide()}): an organization's override
 * that names one is refused rather than stored somewhere it would never be read. They sit
 * under the DEPLOYMENT's ceiling ({@see SignInMethods}), and a value past it is refused
 * with the ceiling named rather than silently cut.
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
            ...self::environmentFields(),
        ];
    }

    /**
     * The environment-wide fields: refused on an organization's override.
     *
     * @return list<Field>
     */
    public static function environmentFields(): array
    {
        return [
            Field::boolean('passkeys')->describe('Environment only. Whether people may sign in with a passkey, and add one. Off at the deployment (CBOX_ID_PASSKEYS_ENABLED=false) stays off.'),
            Field::boolean('magic_link')->describe('Environment only. Whether a one-time sign-in link may be emailed. Off at the deployment (CBOX_ID_MAGIC_LINK_ENABLED=false) stays off.'),
            Field::integer('session_idle_minutes')->nullable()->min(1)->max(525600)->describe('Environment only. Minutes of inactivity before a session ends; null for the deployment\'s. No more than the deployment allows (CBOX_ID_SESSION_IDLE_MINUTES).'),
            Field::integer('session_absolute_minutes')->nullable()->min(5)->max(525600)->describe('Environment only. Minutes a session lasts at most, however active; null for the deployment\'s. No more than the deployment allows (CBOX_ID_SESSION_TTL_MINUTES).'),
            Field::boolean('bot_challenge')->describe('Environment only. Whether a sign-up Radar flags is asked to prove it is a person (Cloudflare Turnstile). Has no effect where the deployment has no Turnstile keys.'),
        ];
    }

    /** The input names of {@see environmentFields()}. */
    public const ENVIRONMENT_ONLY = ['passkeys', 'magic_link', 'session_idle_minutes', 'session_absolute_minutes', 'bot_challenge'];

    /**
     * The environment-only fields a caller sent while writing an organization's override,
     * each with the reason it is refused.
     *
     * @return array<string, string>
     */
    public static function environmentOnly(ActionContext $context): array
    {
        $errors = [];

        foreach (self::ENVIRONMENT_ONLY as $key) {
            if ($context->has($key)) {
                $errors[$key] = 'This is set for the whole environment, not per organization.';
            }
        }

        return $errors;
    }

    /**
     * What a policy asks for past the deployment's ceiling, by input name.
     *
     * @return array<string, string>
     */
    public static function pastTheDeployment(AuthPolicy $policy, SignInMethods $methods): array
    {
        $errors = [];
        $absoluteCeiling = $methods->deploymentSessionAbsoluteMinutes();
        $idleCeiling = $methods->deploymentSessionIdleMinutes();

        if ($policy->sessionAbsoluteMinutes !== null && $policy->sessionAbsoluteMinutes > $absoluteCeiling) {
            $errors['session_absolute_minutes'] = 'This deployment allows sessions of at most '.$absoluteCeiling.' minutes.';
        }

        $absolute = min($policy->sessionAbsoluteMinutes ?? $absoluteCeiling, $absoluteCeiling);

        if ($policy->sessionIdleMinutes !== null) {
            if ($idleCeiling > 0 && $policy->sessionIdleMinutes > $idleCeiling) {
                $errors['session_idle_minutes'] = 'This deployment ends idle sessions after '.$idleCeiling.' minutes at the most.';
            } elseif ($policy->sessionIdleMinutes > $absolute) {
                $errors['session_idle_minutes'] = 'An idle timeout longer than the session itself ('.$absolute.' minutes) would never end anything.';
            }
        }

        return $errors;
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
            passkeys: $context->boolean('passkeys', $base->passkeys),
            magicLink: $context->boolean('magic_link', $base->magicLink),
            sessionIdleMinutes: $int('session_idle_minutes', $base->sessionIdleMinutes),
            sessionAbsoluteMinutes: $int('session_absolute_minutes', $base->sessionAbsoluteMinutes),
            botChallenge: $context->boolean('bot_challenge', $base->botChallenge),
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

        $methods = app(SignInMethods::class);
        $turnstile = app(Turnstile::class);

        return [
            'organization_id' => $organizationId,
            'policy' => self::toArray($organizationId === null ? $baseline : $policies->resolve($organizationId)),
            'baseline' => self::toArray($baseline),
            'override' => $override === null ? null : self::toArray($override),
            'inheriting' => $organizationId !== null && $override === null,
            // What is IN FORCE once the deployment's ceiling is applied — the answer to "can
            // people use a passkey here right now", which the stored value alone is not.
            'in_force' => [
                'passkeys' => $methods->passkeysEnabled(),
                'magic_link' => $methods->magicLinkEnabled(),
                'session_idle_minutes' => $methods->sessionIdleMinutes(),
                'session_absolute_minutes' => $methods->sessionAbsoluteMinutes(),
                'bot_challenge' => $turnstile->enabled(),
            ],
            'deployment' => [
                'passkeys' => $methods->deploymentAllowsPasskeys(),
                'magic_link' => $methods->deploymentAllowsMagicLink(),
                'session_idle_minutes' => $methods->deploymentSessionIdleMinutes(),
                'session_absolute_minutes' => $methods->deploymentSessionAbsoluteMinutes(),
                'bot_challenge' => $turnstile->configured(),
            ],
        ];
    }

    /**
     * @return array{min_length: int, require_breach_check: bool, max_age_days: int|null, reuse_history: int, mfa: string, sso: string, lockout_threshold: int|null, passkeys: bool, magic_link: bool, session_idle_minutes: int|null, session_absolute_minutes: int|null, bot_challenge: bool}
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
            'passkeys' => $policy->passkeys,
            'magic_link' => $policy->magicLink,
            'session_idle_minutes' => $policy->sessionIdleMinutes,
            'session_absolute_minutes' => $policy->sessionAbsoluteMinutes,
            'bot_challenge' => $policy->botChallenge,
        ];
    }

    /**
     * The rules without the environment-only fields — what an organization's form posts.
     *
     * @return array<string, mixed>
     */
    public static function organizationArray(AuthPolicy $policy): array
    {
        return array_diff_key(self::toArray($policy), array_flip(self::ENVIRONMENT_ONLY));
    }
}
