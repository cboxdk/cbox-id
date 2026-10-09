<?php

declare(strict_types=1);

namespace App\Platform\Radar\Enums;

/**
 * What an allow- or deny-list entry names.
 *
 *  - ip: one address or a CIDR range (IPv4 or IPv6);
 *  - email: one address, compared case-insensitively;
 *  - email_domain: a mail domain and every subdomain of it;
 *  - device: a device's pseudonym, as the decisions explorer shows it.
 */
enum RadarListKind: string
{
    case Ip = 'ip';
    case Email = 'email';
    case EmailDomain = 'email_domain';
    case Device = 'device';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
