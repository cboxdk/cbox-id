<?php

declare(strict_types=1);

namespace App\Platform\Radar\Enums;

/**
 * The rules every environment has without writing one.
 *
 * Each can be switched off, given another action, and — where it counts something — another
 * threshold. All of them are evaluated (after the lists and the environment's own rules), and
 * the strictest action among those that fire is the verdict.
 *
 * The defaults are WorkOS-Radar-shaped but deliberately cautious about friction for real
 * people: the two rules that challenge ordinary returning users (`new_device`,
 * `hosting_network`) start switched OFF, because under enforcement each would send an emailed
 * code to everybody on a new laptop or a corporate egress. Their facts are recorded either way,
 * so the decisions explorer shows what switching them on would do before anybody does it.
 */
enum RadarBuiltin: string
{
    case CredentialStuffing = 'credential_stuffing';
    case BotVelocity = 'bot_velocity';
    case AccountAttack = 'account_attack';
    case ImpossibleTravel = 'impossible_travel';
    case NewDevice = 'new_device';
    case AnonymousNetwork = 'anonymous_network';
    case HostingNetwork = 'hosting_network';
    case DisposableEmail = 'disposable_email';
    case RiskScoreReject = 'risk_score_reject';
    case RiskScoreElevated = 'risk_score_elevated';

    public function label(): string
    {
        return match ($this) {
            self::CredentialStuffing => 'Credential stuffing',
            self::BotVelocity => 'Bot-like velocity',
            self::AccountAttack => 'Repeated failures on one account',
            self::ImpossibleTravel => 'Impossible travel',
            self::NewDevice => 'New device',
            self::AnonymousNetwork => 'Tor, VPN or open proxy',
            self::HostingNetwork => 'Hosting or data centre network',
            self::DisposableEmail => 'Disposable email domain',
            self::RiskScoreReject => 'Risk score: reject',
            self::RiskScoreElevated => 'Risk score: elevated',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CredentialStuffing => 'Many different addresses tried from one IP address within 10 minutes — a list of leaked credentials being worked through.',
            self::BotVelocity => 'More attempts per minute from one IP address, or one device, than a person makes.',
            self::AccountAttack => 'Failed sign-ins on one address within an hour, from anywhere — guessing spread across many addresses.',
            self::ImpossibleTravel => 'The account\'s last successful sign-in was too far away, too recently, to have travelled from. Needs IP intelligence.',
            self::NewDevice => 'An account that has signed in before, from a browser it has never signed in from.',
            self::AnonymousNetwork => 'The address is a Tor exit node, a VPN or an open proxy. Tor is known from the local exit list; VPN and proxy need IP intelligence that reports them.',
            self::HostingNetwork => 'The address belongs to a hosting provider or data centre, where people rarely sign in from. Needs IP intelligence that reports it.',
            self::DisposableEmail => 'A sign-up with an address at a throwaway mail provider.',
            self::RiskScoreReject => 'The risk score reached its reject threshold (IP reputation, honeypot, user agent, MX and the other scored signals).',
            self::RiskScoreElevated => 'The risk score reached its challenge or step-up threshold.',
        };
    }

    public function defaultEnabled(): bool
    {
        return ! in_array($this, [self::NewDevice, self::HostingNetwork], true);
    }

    public function defaultAction(): RadarAction
    {
        return match ($this) {
            self::CredentialStuffing, self::BotVelocity, self::DisposableEmail, self::RiskScoreReject => RadarAction::Block,
            default => RadarAction::Challenge,
        };
    }

    /** The default threshold, or null for a rule that counts nothing. */
    public function defaultThreshold(): ?int
    {
        return match ($this) {
            self::CredentialStuffing => 10,
            self::BotVelocity => 20,
            self::AccountAttack => 10,
            self::ImpossibleTravel => 900,
            default => null,
        };
    }

    /** @return array{0: int, 1: int}|null */
    public function thresholdBounds(): ?array
    {
        return match ($this) {
            self::CredentialStuffing => [2, 1000],
            self::BotVelocity => [2, 10000],
            self::AccountAttack => [2, 1000],
            self::ImpossibleTravel => [100, 20000],
            default => null,
        };
    }

    /** What the threshold counts, for the console. */
    public function thresholdUnit(): ?string
    {
        return match ($this) {
            self::CredentialStuffing => 'addresses per IP in 10 minutes',
            self::BotVelocity => 'attempts per minute',
            self::AccountAttack => 'failures per hour',
            self::ImpossibleTravel => 'km/h',
            default => null,
        };
    }

    /** The flows the rule is asked on. */
    public function scope(): RadarRuleScope
    {
        return match ($this) {
            self::DisposableEmail => RadarRuleScope::SignUp,
            self::CredentialStuffing, self::AccountAttack, self::ImpossibleTravel, self::NewDevice => RadarRuleScope::SignIn,
            default => RadarRuleScope::All,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $rule): string => $rule->value, self::cases());
    }
}
