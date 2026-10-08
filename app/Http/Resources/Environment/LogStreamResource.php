<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use App\Platform\Integrations\LogStreamDestinations;
use Cbox\Id\AuditStreaming\Models\AuditStream;

/**
 * An audit log stream — `LogStream` in the spec: a SIEM destination this environment's
 * audit trail is mirrored to. `organization_id` null means it carries every
 * organization's entries. `secret` only on the answer that generated it — the stream keeps
 * ciphertext alone, and a credential the caller supplied is never echoed.
 *
 * `options` are a cloud destination's settings (site, bucket, region, …) and hold nothing
 * secret: the API key, secret access key or service-account key is the encrypted `secret`.
 * `external_id` is an assumed-role S3 stream's, for the role's trust policy. `health` is
 * what to show beside the stream; `action_required` means the destination refused the
 * credentials or the settings, and only fixing the stream (or a successful test) clears it.
 */
final class LogStreamResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(AuditStream $stream, ?string $secret = null): array
    {
        $organizationId = $stream->getAttribute('organization_id');
        $options = $stream->destinationOptions();

        $data = [
            'id' => $stream->id,
            'name' => $stream->name,
            'destination' => $stream->destination->value,
            'endpoint_url' => $stream->endpoint_url,
            'auth' => $stream->auth->value,
            'options' => $options === [] ? null : $options,
            'external_id' => LogStreamDestinations::externalId($stream),
            'organization_id' => is_string($organizationId) ? $organizationId : null,
            'enabled' => $stream->enabled,
            'health' => $stream->health()->value,
            'consecutive_failures' => $stream->consecutive_failures,
            'last_success_at' => Timestamp::of($stream->last_success_at),
            'last_error' => $stream->last_error,
            'last_failure_kind' => $stream->last_failure_kind?->value,
            'last_failure_at' => Timestamp::of($stream->last_failure_at),
            'created_at' => Timestamp::of($stream->created_at),
        ];

        if ($secret !== null) {
            $data['secret'] = $secret;
        }

        return $data;
    }
}
