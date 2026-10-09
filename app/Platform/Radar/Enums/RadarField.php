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
            self::Ip => 'IP address',
            self::Country => 'Country',
            self::Asn => 'Network (ASN)',
            self::AsOrganization => 'Network owner',
            self::IsHosting => 'Hosting or data centre network',
            self::IsVpn => 'VPN',
            self::IsProxy => 'Open proxy',
            self::IsTor => 'Tor exit node',
            self::Email => 'Email address',
            self::EmailDomain => 'Email domain',
            self::DisposableEmail => 'Disposable email domain',
            self::UserAgent => 'User agent',
            self::Method => 'Sign-in method',
            self::NewDevice => 'New device',
            self::ImpossibleTravel => 'Impossible travel',
            self::TravelKmh => 'Implied travel speed (km/h)',
            self::RiskScore => 'Risk score',
            self::IpAttempts1m => 'Attempts from this IP, last minute',
            self::IpAttempts1h => 'Attempts from this IP, last hour',
            self::IpDistinctEmails10m => 'Different addresses tried from this IP, last 10 minutes',
            self::IpFailures1h => 'Failed sign-ins from this IP, last hour',
            self::EmailAttempts1h => 'Attempts on this address, last hour',
            self::EmailFailures1h => 'Failed sign-ins on this address, last hour',
            self::DeviceAttempts1h => 'Attempts from this device, last hour',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $field): string => $field->value, self::cases());
    }
}
