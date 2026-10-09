<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use App\Platform\Radar\Enums\RadarAction;
use App\Platform\Radar\Enums\RadarBuiltin;
use App\Platform\Radar\Enums\RadarField;
use App\Platform\Radar\Enums\RadarList;
use App\Platform\Radar\Enums\RadarListKind;
use Cbox\Risk\Enums\Outcome;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * THE ORDER RADAR ASKS ITS QUESTIONS IN — a pure function of the facts and the environment's
 * configuration, so the order is testable and is the same on every door.
 *
 *  1. The DENY list. A match blocks, and nothing after it is asked.
 *  2. The ALLOW list. A match allows, and no rule is asked.
 *  3. The environment's OWN rules, in their order. The first whose conditions all hold
 *     decides — allow included, which is how an exception is carved out of what follows.
 *  4. The BUILT-IN rules, all of them. The strictest action among those that fire decides.
 *  5. Nothing matched: allow.
 *
 * Deny before allow, so an address on both is refused: a deny entry is the more deliberate
 * of the two, and the cost of being wrong the other way is an attacker let through.
 *
 * The built-in rules are evaluated even when an earlier step decided, and recorded as
 * `triggered`: the explorer can then say "allowed by your rule, though credential stuffing
 * fired", which is exactly what an administrator who wrote that rule needs to see.
 */
final class RadarEngine
{
    public static function evaluate(RadarFacts $facts, RadarPolicySnapshot $policy): RadarVerdict
    {
        [$builtinTriggered, $builtinReasons, $builtinDecision] = self::builtins($facts, $policy);

        $list = self::listMatch($facts, $policy, RadarList::Deny) ?? self::listMatch($facts, $policy, RadarList::Allow);

        if ($list !== null) {
            [$which, $kind] = $list;
            $key = $which->value.'_list:'.$kind->value;
            $action = $which === RadarList::Deny ? RadarAction::Block : RadarAction::Allow;
            $reason = $which === RadarList::Deny
                ? "The {$kind->value} is on the deny list."
                : "The {$kind->value} is on the allow list.";

            return new RadarVerdict($action, $key, ucfirst($which->value).' list', [$key, ...$builtinTriggered], [$reason, ...$builtinReasons]);
        }

        foreach ($policy->rules as $rule) {
            if (! $rule['scope']->covers($facts->flow) || ! self::allHold($rule['conditions'], $facts)) {
                continue;
            }

            $key = 'rule:'.$rule['id'];

            return new RadarVerdict(
                $rule['action'],
                $key,
                $rule['name'],
                [$key, ...$builtinTriggered],
                ["Matched the rule \"{$rule['name']}\".", ...$builtinReasons],
            );
        }

        if ($builtinDecision !== null) {
            return new RadarVerdict(
                $builtinDecision['action'],
                'builtin:'.$builtinDecision['rule']->value,
                $builtinDecision['rule']->label(),
                $builtinTriggered,
                $builtinReasons,
            );
        }

        return RadarVerdict::allow();
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     */
    private static function allHold(array $conditions, RadarFacts $facts): bool
    {
        if ($conditions === []) {
            return false;
        }

        foreach ($conditions as $condition) {
            if (! RadarConditions::matches($condition, $facts)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every enabled built-in rule that fires, its reasons, and the strictest of them.
     *
     * @return array{0: list<string>, 1: list<string>, 2: array{rule: RadarBuiltin, action: RadarAction}|null}
     */
    private static function builtins(RadarFacts $facts, RadarPolicySnapshot $policy): array
    {
        $triggered = [];
        $reasons = [];
        $decision = null;

        foreach (RadarBuiltin::cases() as $rule) {
            $setting = $policy->builtin($rule);

            if (! $setting['enabled'] || ! $rule->scope()->covers($facts->flow)) {
                continue;
            }

            $reason = self::fires($rule, $facts, $policy->threshold($rule));

            if ($reason === null) {
                continue;
            }

            $triggered[] = 'builtin:'.$rule->value;
            $reasons[] = $reason;

            if ($decision === null || $setting['action']->severity() > $decision['action']->severity()) {
                $decision = ['rule' => $rule, 'action' => $setting['action']];
            }
        }

        return [$triggered, $reasons, $decision];
    }

    /** The sentence a built-in rule fires with, or null when it does not. */
    private static function fires(RadarBuiltin $rule, RadarFacts $facts, int $threshold): ?string
    {
        return match ($rule) {
            RadarBuiltin::CredentialStuffing => self::atLeast($facts, RadarField::IpDistinctEmails10m, $threshold, '%d different addresses tried from this IP in 10 minutes (threshold %d).'),
            RadarBuiltin::BotVelocity => self::atLeast($facts, RadarField::IpAttempts1m, $threshold, '%d attempts from this IP in a minute (threshold %d).'),
            RadarBuiltin::AccountAttack => self::atLeast($facts, RadarField::EmailFailures1h, $threshold, '%d failed sign-ins on this address in an hour (threshold %d).'),
            RadarBuiltin::ImpossibleTravel => self::atLeast($facts, RadarField::TravelKmh, $threshold, 'Travel at %d km/h since the last sign-in (threshold %d).'),
            RadarBuiltin::NewDevice => $facts->bool(RadarField::NewDevice) ? 'A device this account has not signed in from before.' : null,
            RadarBuiltin::AnonymousNetwork => match (true) {
                $facts->bool(RadarField::IsTor) => 'The address is a Tor exit node.',
                $facts->bool(RadarField::IsVpn) => 'The address is a VPN.',
                $facts->bool(RadarField::IsProxy) => 'The address is an open proxy.',
                default => null,
            },
            RadarBuiltin::HostingNetwork => $facts->bool(RadarField::IsHosting) ? 'The address belongs to a hosting provider or data centre.' : null,
            RadarBuiltin::DisposableEmail => $facts->bool(RadarField::DisposableEmail) ? 'The address is at a disposable mail provider.' : null,
            RadarBuiltin::RiskScoreReject => $facts->riskOutcome === Outcome::Reject
                ? sprintf('Risk score %d reached the reject threshold.', (int) round($facts->number(RadarField::RiskScore) ?? 0))
                : null,
            RadarBuiltin::RiskScoreElevated => in_array($facts->riskOutcome, [Outcome::Challenge, Outcome::StepUp], true)
                ? sprintf('Risk score %d reached the challenge threshold.', (int) round($facts->number(RadarField::RiskScore) ?? 0))
                : null,
        };
    }

    private static function atLeast(RadarFacts $facts, RadarField $field, int $threshold, string $sentence): ?string
    {
        $value = $facts->number($field);

        return $value !== null && $value >= $threshold ? sprintf($sentence, (int) round($value), $threshold) : null;
    }

    /**
     * @return array{0: RadarList, 1: RadarListKind}|null
     */
    private static function listMatch(RadarFacts $facts, RadarPolicySnapshot $policy, RadarList $list): ?array
    {
        $ip = $facts->get(RadarField::Ip);
        $ip = is_string($ip) ? RadarAddresses::canonicalIp($ip) : null;
        $email = $facts->get(RadarField::Email);
        $email = is_string($email) ? RadarPseudonyms::canonicalEmail($email) : null;
        $domain = $facts->get(RadarField::EmailDomain);
        $domain = is_string($domain) ? strtolower($domain) : null;

        foreach ($policy->entries as $entry) {
            if ($entry['list'] !== $list) {
                continue;
            }

            $value = $entry['value'];

            $hit = match ($entry['kind']) {
                RadarListKind::Ip => $ip !== null && IpUtils::checkIp($ip, $value),
                RadarListKind::Email => $email !== null && $email === $value,
                RadarListKind::EmailDomain => $domain !== null && ($domain === $value || str_ends_with($domain, '.'.$value)),
                RadarListKind::Device => $facts->deviceHash !== null && hash_equals($value, $facts->deviceHash),
            };

            if ($hit) {
                return [$list, $entry['kind']];
            }
        }

        return null;
    }
}
