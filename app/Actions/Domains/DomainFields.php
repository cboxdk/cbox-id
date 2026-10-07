<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Http\Resources\Environment\Timestamp;
use Cbox\Id\Organization\Contracts\EnvironmentDomains;
use Cbox\Id\Organization\Models\Environment;

/**
 * An environment's custom domain as the management API returns it — `CustomDomain` in the
 * spec. A helper, not an action.
 *
 * Two facts, because there can be two domains at once: the one VERIFIED and serving the
 * environment now, and one PENDING its DNS proof — somebody moving to a new domain keeps
 * the old one working until the new one is proved.
 */
final class DomainFields
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Environment $environment, EnvironmentDomains $domains): array
    {
        $pending = $domains->challenge($environment->id);

        return [
            'domain' => $environment->domain,
            'verified_at' => Timestamp::of($environment->domain_verified_at),
            'pending' => $pending === null ? null : [
                'domain' => $pending->domain,
                // The TXT record to publish: its name, and the exact value.
                'record_name' => $pending->recordName,
                'record_value' => $pending->recordValue,
            ],
        ];
    }
}
