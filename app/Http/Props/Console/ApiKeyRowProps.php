<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use DateTimeInterface;

/**
 * One account API key in the list.
 *
 * `revokeHref` is null for a key that is no longer active: there is nothing left to stop,
 * and a Revoke button on a revoked key reads as though the first revocation did not take.
 */
final readonly class ApiKeyRowProps implements Prop
{
    public function __construct(
        public string $id,
        public string $name,
        public string $prefix,
        public string $role,
        public KeyLifecycleProps $lifecycle,
        public ?string $revokeHref,
    ) {}

    public static function from(OrganizationApiKey $key, string $revokeHref, DateTimeInterface $now): self
    {
        $lifecycle = KeyLifecycleProps::of(KeyLifecycleProps::createdAt($key), $key->last_used_at, $key->expires_at, $key->revoked_at, $now);

        return new self(
            id: $key->id,
            name: $key->name,
            prefix: $key->prefix,
            role: $key->role->label(),
            lifecycle: $lifecycle,
            revokeHref: $lifecycle->active() ? $revokeHref : null,
        );
    }

    /**
     * @return array{id: string, name: string, prefix: string, role: string, lifecycle: array<string, string|null>, revokeHref: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'prefix' => $this->prefix,
            'role' => $this->role,
            'lifecycle' => $this->lifecycle->toArray(),
            'revokeHref' => $this->revokeHref,
        ];
    }
}
