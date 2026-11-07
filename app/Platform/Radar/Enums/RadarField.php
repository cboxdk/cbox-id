<?php

declare(strict_types=1);

namespace App\Platform\Radar\Enums;

/**
 * A fact about an attempt that a rule can test.
 *
 * Every fact may be UNKNOWN — no IP intelligence configured, no address on a passkey
 * sign-in, no history for a new account — and an unknown fact matches NO condition, whatever
 * the operator. So `country is not one of [DK, SE]` never challenges everyone merely because
 * nothing can locate them; it challenges the attempts it can place outside those two.
 */
enum RadarField: string
{
    case Ip = 'ip';
    case Country = 'country';
    case Asn = 'asn';
    case AsOrganization = 'as_organization';
    case IsHosting = 'is_hosting';
    case IsVpn = 'is_vpn';
    case IsProxy = 'is_proxy';
    case IsTor = 'is_tor';
    case Email = 'email';
    case EmailDomain = 'email_domain';
    case DisposableEmail = 'disposable_email';
    case UserAgent = 'user_agent';
    case Method = 'method';
    case NewDevice = 'new_device';
    case ImpossibleTravel = 'impossible_travel';
    case TravelKmh = 'travel_kmh';
    case RiskScore = 'risk_score';
    case IpAttempts1m = 'ip_attempts_1m';
    case IpAttempts1h = 'ip_attempts_1h';
    case IpDistinctEmails10m = 'ip_distinct_emails_10m';
    case IpFailures1h = 'ip_failures_1h';
    case EmailAttempts1h = 'email_attempts_1h';
    case EmailFailures1h = 'email_failures_1h';
    case DeviceAttempts1h = 'device_attempts_1h';

    /** `string`, `number`, `boolean`, `ip` or `country`. */
    public function type(): string
    {
        return match ($this) {
            self::Ip => 'ip',
            self::Country => 'country',
            self::Asn, self::TravelKmh, self::RiskScore, self::IpAttempts1m, self::IpAttempts1h,
            self::IpDistinctEmails10m, self::IpFailures1h, self::EmailAttempts1h,
            self::EmailFailures1h, self::DeviceAttempts1h => 'number',
            self::IsHosting, self::IsVpn, self::IsProxy, self::IsTor, self::DisposableEmail,
            self::NewDevice, self::ImpossibleTravel => 'boolean',
            default => 'string',
        };
    }

    /** @return list<RadarOperator> */
    public function operators(): array
    {
        return match ($this->type()) {
            'ip' => [RadarOperator::Equals, RadarOperator::NotEquals, RadarOperator::In, RadarOperator::NotIn, RadarOperator::InCidr, RadarOperator::NotInCidr],
            'country' => [RadarOperator::Equals, RadarOperator::NotEquals, RadarOperator::In, RadarOperator::NotIn],
            'number' => [RadarOperator::Equals, RadarOperator::NotEquals, RadarOperator::GreaterThan, RadarOperator::GreaterThanOrEqual, RadarOperator::LessThan, RadarOperator::LessThanOrEqual, RadarOperator::In, RadarOperator::NotIn],
            'boolean' => [RadarOperator::Equals, RadarOperator::NotEquals],
            default => [RadarOperator::Equals, RadarOperator::NotEquals, RadarOperator::In, RadarOperator::NotIn, RadarOperator::Contains, RadarOperator::NotContains, RadarOperator::StartsWith, RadarOperator::EndsWith],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ip => __('console.ip_address_0302a467'),
            self::Country => __('console.country_701d021d'),
            self::Asn => __('console.network_asn_7307543b'),
            self::AsOrganization => __('console.network_owner_7da1e69c'),
            self::IsHosting => __('console.hosting_or_data_centre_network_291e0a01'),
            self::IsVpn => __('console.vpn_2762a067'),
            self::IsProxy => __('console.open_proxy_be24baf9'),
            self::IsTor => __('console.tor_exit_node_8a523ae1'),
            self::Email => __('console.email_address_f2488fd4'),
            self::EmailDomain => __('console.email_domain_f865f1ee'),
            self::DisposableEmail => __('console.disposable_email_domain_a9966c59'),
            self::UserAgent => __('console.user_agent_825af78f'),
            self::Method => __('console.sign_in_method_2bb30a7e'),
            self::NewDevice => __('console.new_device_30d01a64'),
            self::ImpossibleTravel => __('console.impossible_travel_320ad536'),
            self::TravelKmh => __('console.implied_travel_speed_km_h_49ed3aa2'),
            self::RiskScore => __('console.risk_score_a4db9264'),
            self::IpAttempts1m => __('console.attempts_from_this_ip_last_minute_c72e9262'),
            self::IpAttempts1h => __('console.attempts_from_this_ip_last_hour_8fd5ddc2'),
            self::IpDistinctEmails10m => __('console.different_addresses_tried_from_this_ip_last_10_minutes_19340a4e'),
            self::IpFailures1h => __('console.failed_sign_ins_from_this_ip_last_hour_423179bf'),
            self::EmailAttempts1h => __('console.attempts_on_this_address_last_hour_311060c0'),
            self::EmailFailures1h => __('console.failed_sign_ins_on_this_address_last_hour_830d6c25'),
            self::DeviceAttempts1h => __('console.attempts_from_this_device_last_hour_f5f1f4cc'),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $field): string => $field->value, self::cases());
    }
}
