<?php

declare(strict_types=1);

namespace App\Http\Resources\Workspace;

use Cbox\Id\Organization\Models\Environment;

/**
 * An environment as the workspace sees it — `Environment` in the workspace spec: which
 * project it belongs to, and the issuer its tokens carry.
 */
final class EnvironmentResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Environment $environment): array
    {
        $base = config('cbox-id.environments.base_domains', []);
        $first = is_array($base) && isset($base[0]) && is_string($base[0]) ? $base[0] : null;
        $baseDomain = $first ?? request()->getHost();

        return [
            'id' => $environment->id,
            'name' => $environment->name,
            'slug' => $environment->slug,
            'type' => $environment->type->value,
            'status' => $environment->status->value,
            // Which project (billing anchor) this environment belongs to.
            'project_id' => $environment->getAttribute('project_id'),
            'domain' => $environment->domain,
            // Only a VERIFIED domain may stand as the issuer — that is exactly the rule
            // EnvironmentIssuerResolver enforces, and reporting a different one here
            // would tell an integrator to configure an issuer the server will not assert.
            'issuer' => 'https://'.($environment->domain_verified_at !== null && $environment->domain !== null
                ? $environment->domain
                : $environment->slug.'.'.$baseDomain),
        ];
    }
}
