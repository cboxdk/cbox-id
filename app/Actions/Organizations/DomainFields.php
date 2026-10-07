<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Platform\Actions\ActionRefused;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Models\VerifiedDomain;

/**
 * Shared lookups and the wire shape for an organization's claimed email domains. A helper,
 * not an action.
 */
final class DomainFields
{
    /**
     * A domain, resolved INSIDE the organization the path names rather than checked after
     * loading it: the id arrives in the URL, and a verify or a capture toggle on somebody
     * else's claimed domain is a cross-tenant write.
     *
     * @throws ActionRefused
     */
    public static function find(string $organizationId, string $domainId): VerifiedDomain
    {
        return VerifiedDomain::query()
            ->whereKey($domainId)
            ->where('organization_id', $organizationId)
            ->first() ?? throw ActionRefused::notFound('domain');
    }

    /**
     * `OrganizationDomain` in the spec. The TXT record is handed over as its own two fields
     * — where to publish it and what to publish — because it is the one value somebody has
     * to copy into a DNS panel.
     *
     * @return array<string, mixed>
     */
    public static function present(VerifiedDomain $domain): array
    {
        return [
            'id' => $domain->id,
            'organization_id' => $domain->organization_id,
            'domain' => $domain->domain,
            'verified' => $domain->isVerified(),
            'verified_at' => $domain->verified_at?->toIso8601String(),
            'capture' => $domain->capture,
            'record_name' => app(DomainVerification::class)->challengeHost($domain->domain),
            'record_value' => $domain->verification_token,
        ];
    }
}
