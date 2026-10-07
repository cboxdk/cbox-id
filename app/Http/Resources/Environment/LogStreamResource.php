<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use Cbox\Id\AuditStreaming\Models\AuditStream;

/**
 * An audit log stream — `LogStream` in the spec: a SIEM destination this environment's
 * audit trail is mirrored to. `organization_id` null means it carries every
 * organization's entries. `secret` only on the answer that generated or accepted it — the
 * stream keeps ciphertext alone.
 */
final class LogStreamResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(AuditStream $stream, ?string $secret = null): array
    {
        $organizationId = $stream->getAttribute('organization_id');

        $data = [
            'id' => $stream->id,
            'name' => $stream->name,
            'destination' => $stream->destination->value,
            'endpoint_url' => $stream->endpoint_url,
            'auth' => $stream->auth->value,
            'organization_id' => is_string($organizationId) ? $organizationId : null,
            'enabled' => $stream->enabled,
            'consecutive_failures' => $stream->consecutive_failures,
            'last_success_at' => Timestamp::of($stream->last_success_at),
            'created_at' => Timestamp::of($stream->created_at),
        ];

        if ($secret !== null) {
            $data['secret'] = $secret;
        }

        return $data;
    }
}
