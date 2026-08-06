<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Dns\Enums\RecordType;
use Cbox\Dns\Exceptions\DnsException;
use Cbox\Dns\Resolvers\AuthoritativeResolver;
use Cbox\Id\Federation\Contracts\DnsResolver;

/**
 * Reads a challenge host's TXT records straight from the domain's AUTHORITATIVE
 * nameservers (via cboxdk/dns), instead of the framework's default recursive
 * `dns_get_record()`.
 *
 * Why: recursive resolvers negatively cache a missing record (NXDOMAIN) for the
 * zone's minimum TTL. An org that has just published its verification TXT would
 * then fail to verify for minutes — the classic "but I added the record!" support
 * ticket. Querying the authoritative servers directly sees the record the moment
 * it is live. Fail-closed: any resolution problem yields no records, i.e. "not
 * verified", never a false pass.
 */
final class AuthoritativeDnsResolver implements DnsResolver
{
    public function __construct(private readonly AuthoritativeResolver $authoritative) {}

    public function txtRecords(string $host): array
    {
        $host = rtrim(strtolower(trim($host)), '.');

        if ($host === '') {
            return [];
        }

        // Walk up from the challenge host's parent to find the zone whose
        // authoritative servers actually answer (a `_challenge.example.com` host
        // is never itself a zone). The first candidate with reachable authoritative
        // nameservers answers; an empty answer is a legitimate "not verified".
        $labels = explode('.', $host);

        for ($i = 1, $n = count($labels); $i < $n - 1; $i++) {
            $zone = implode('.', array_slice($labels, $i));

            try {
                return $this->authoritative->query($host, RecordType::TXT, $zone)->values();
            } catch (DnsException) {
                // No authoritative nameserver for this candidate zone — walk up.
            }
        }

        return [];
    }
}
