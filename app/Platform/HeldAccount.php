<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * One concurrently signed-in account in the multi-account set (Notion/Slack style):
 * the framework session id that keeps it authenticated and the organization it was
 * last in. The owning subject id is the map key in {@see PlatformAuth}, so it is not
 * repeated here. The session cookie stores the flat {@see toArray()} shape; the set
 * is hydrated back into these typed objects on read, so the rest of the auth code
 * reads `$held->sessionId` rather than a stringly-keyed array.
 */
final readonly class HeldAccount
{
    public function __construct(
        public string $sessionId,
        public ?string $organizationId,
    ) {}

    /**
     * Re-narrow one raw session entry, or null when it is malformed (no session id).
     */
    public static function fromArray(mixed $entry): ?self
    {
        if (! is_array($entry) || ! is_string($entry['session'] ?? null)) {
            return null;
        }

        $org = $entry['org'] ?? null;

        return new self($entry['session'], is_string($org) ? $org : null);
    }

    /**
     * @return array{session: string, org: ?string}
     */
    public function toArray(): array
    {
        return ['session' => $this->sessionId, 'org' => $this->organizationId];
    }

    public function withOrganization(?string $organizationId): self
    {
        return new self($this->sessionId, $organizationId);
    }
}
