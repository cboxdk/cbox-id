<?php

declare(strict_types=1);

namespace App\Platform\PublicIds;

use App\Http\Middleware\V1Parameters;
use App\Platform\Erasure\PrefixedWebhookDeliveriesErasureStep;
use Cbox\Id\Webhooks\Models\WebhookDelivery;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;

/**
 * THE IDS A WEBHOOK ENDPOINT RECEIVES: bare, as every endpoint has received them so far, or
 * public (`usr_01j…`), per endpoint (plan §3.2, expand before contract).
 *
 * Every existing endpoint stays `raw`: each Cbox app's user mirror is joined on the bare id
 * (the same id as the token's `sub`, which does not change, §8 Q2), so flipping a live
 * receiver would orphan its rows. An endpoint made through `/v1` starts `prefixed` (the
 * default {@see V1Parameters::INPUT_DEFAULTS} puts in as the request
 * arrives, so a held create keeps the surface it was asked on); any
 * endpoint can be moved, both ways, in the console or with `PATCH /v1/webhooks/{id}`.
 *
 * The payload is rewritten as the delivery is recorded, so what the delivery log shows is
 * what was signed and sent, and a retry sends the same body. Everything that reads a stored
 * payload back as data reads the bare ids again: the delivery policy that is asked once more
 * before each attempt ({@see BareIdDeliveryPolicy}) and erasure
 * ({@see PrefixedWebhookDeliveriesErasureStep}).
 */
final class WebhookIdFormat
{
    public const string RAW = 'raw';

    public const string PREFIXED = 'prefixed';

    public const array FORMATS = [self::RAW, self::PREFIXED];

    /**
     * The kind of record an event's `data.id` is, by the event's family. An event whose
     * `id` is not one record of a known kind keeps it bare; its `<kind>_id` fields are still
     * public ids.
     *
     * @var array<string, string>
     */
    private const array EVENT_SUBJECTS = [
        'user' => 'user',
        'organization' => 'organization',
        'membership' => 'membership',
        'invitation' => 'invitation',
        'domain' => 'verified_domain',
        'connection' => 'sso_connection',
        'feature_flag' => 'feature_flag',
        'directory.user' => 'directory_user',
        'directory.group' => 'directory_group',
        'api_key' => 'customer_api_key',
        'support_session' => 'support_session',
        'vault.secret' => 'vault_secret',
    ];

    /** Rewrite each new delivery's payload for an endpoint that asked for public ids. */
    public static function register(): void
    {
        WebhookDelivery::creating(static function (WebhookDelivery $delivery): void {
            $endpointId = $delivery->getAttribute('endpoint_id');

            if (! is_string($endpointId) || self::of($endpointId) !== self::PREFIXED) {
                return;
            }

            $payload = $delivery->getAttribute('payload');
            $type = $delivery->getAttribute('event_type');

            if (is_array($payload)) {
                $delivery->setAttribute('payload', self::payload(is_string($type) ? $type : '', $payload));
            }
        });
    }

    /**
     * A payload with public ids.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function payload(string $eventType, array $payload): array
    {
        return PublicIdTree::encode($payload, self::subjectOf($eventType));
    }

    public static function subjectOf(string $eventType): ?string
    {
        $type = str_starts_with($eventType, 'id.') ? substr($eventType, 3) : $eventType;
        $parts = explode('.', $type);

        return self::EVENT_SUBJECTS[$parts[0].'.'.($parts[1] ?? '')] ?? self::EVENT_SUBJECTS[$parts[0]] ?? null;
    }

    /** The format an endpoint's deliveries carry. */
    public static function of(string $endpointId): string
    {
        $format = WebhookEndpoint::query()->whereKey($endpointId)->value('id_format');

        return $format === self::PREFIXED ? self::PREFIXED : self::RAW;
    }

    public static function ofEndpoint(WebhookEndpoint $endpoint): string
    {
        return $endpoint->getAttribute('id_format') === self::PREFIXED ? self::PREFIXED : self::RAW;
    }
}
