<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\ValueObjects;

/**
 * What a single push says and carries.
 *
 * The split between `title`/`body` and `data` is a security boundary, not a formatting
 * one. `title` and `body` are rendered on the LOCK SCREEN, readable by anyone holding
 * the handset, so they are deliberately vague: "Approval request", not the client name
 * and certainly not the CIBA binding message — which is the transaction description
 * ("Transfer DKK 4,200 to ACME ApS") and precisely the detail CIBA exists to protect.
 * The specifics are fetched over TLS after the app opens and the user has authenticated.
 *
 * `data` therefore carries only routing information, and the app must treat it as
 * UNTRUSTED: it re-fetches the approval by id and renders that response, never the
 * push contents.
 */
final readonly class PushPayload
{
    /**
     * @param  array<string, string>  $data  FCM data values are strings on the wire;
     *                                       anything structured must be JSON-encoded
     *                                       into one by the caller.
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $data = [],
        public ?string $collapseKey = null,
    ) {}

    /**
     * Normalise a decoded JSON object into a payload.
     *
     * The key type is `array-key` rather than `string` because that is genuinely what
     * `json_decode(..., true)` hands back — the input is untrusted stored data, not a
     * caller-constructed shape, and every field is validated below rather than assumed.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $data = [];

        $rawData = $raw['data'] ?? [];

        if (is_array($rawData)) {
            foreach ($rawData as $key => $value) {
                if (is_string($key) && (is_string($value) || is_int($value))) {
                    $data[$key] = (string) $value;
                }
            }
        }

        $collapseKey = $raw['collapse_key'] ?? null;

        return new self(
            title: is_string($raw['title'] ?? null) ? $raw['title'] : '',
            body: is_string($raw['body'] ?? null) ? $raw['body'] : '',
            data: $data,
            collapseKey: is_string($collapseKey) && $collapseKey !== '' ? $collapseKey : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'collapse_key' => $this->collapseKey,
        ];
    }
}
