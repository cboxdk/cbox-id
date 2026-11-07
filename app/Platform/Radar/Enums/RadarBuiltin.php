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
            self::CredentialStuffing => __('console.credential_stuffing_55181863'),
            self::BotVelocity => __('console.bot_like_velocity_5598a16c'),
            self::AccountAttack => __('console.repeated_failures_on_one_account_9a4ad01b'),
            self::ImpossibleTravel => __('console.impossible_travel_320ad536'),
            self::NewDevice => __('console.new_device_30d01a64'),
            self::AnonymousNetwork => __('console.tor_vpn_or_open_proxy_2fff7c74'),
            self::HostingNetwork => __('console.hosting_or_data_centre_network_291e0a01'),
            self::DisposableEmail => __('console.disposable_email_domain_a9966c59'),
            self::RiskScoreReject => __('console.risk_score_reject_bc14c1e0'),
            self::RiskScoreElevated => __('console.risk_score_elevated_22d91dd2'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CredentialStuffing => __('console.many_different_addresses_tried_from_one_ip_address_with_68db7387'),
            self::BotVelocity => __('console.more_attempts_per_minute_from_one_ip_address_or_one_dev_19f2637a'),
            self::AccountAttack => __('console.failed_sign_ins_on_one_address_within_an_hour_from_anyw_cdda8940'),
            self::ImpossibleTravel => __('console.the_account_s_last_successful_sign_in_was_too_far_away_e6d46632'),
            self::NewDevice => __('console.an_account_that_has_signed_in_before_from_a_browser_it_7c5750f4'),
            self::AnonymousNetwork => __('console.the_address_is_a_tor_exit_node_a_vpn_or_an_open_proxy_t_f7fc0d8a'),
            self::HostingNetwork => __('console.the_address_belongs_to_a_hosting_provider_or_data_centr_213f7032'),
            self::DisposableEmail => __('console.a_sign_up_with_an_address_at_a_throwaway_mail_provider_d4a56228'),
            self::RiskScoreReject => __('console.the_risk_score_reached_its_reject_threshold_ip_reputati_e04e768d'),
            self::RiskScoreElevated => __('console.the_risk_score_reached_its_challenge_or_step_up_thresho_fc5a2473'),
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
