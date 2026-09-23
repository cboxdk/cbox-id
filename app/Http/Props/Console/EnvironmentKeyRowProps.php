<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use DateTimeInterface;

/**
 * One environment API key in the list.
 *
 * `revokeHref` is null for a key that is no longer active. The list is the audit list —
 * the framework returns revoked and expired keys on purpose — and it drew every one of
 * them exactly like a live key, Revoke button included.
 */
final readonly class EnvironmentKeyRowProps implements Prop
{
    /**
     * @param  list<EnvironmentScopeProps>  $scopes
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $prefix,
        public array $scopes,
        public KeyLifecycleProps $lifecycle,
        public ?string $revokeHref,
    ) {}

    public static function from(EnvironmentApiKey $key, string $revokeHref, DateTimeInterface $now): self
    {
        $lifecycle = KeyLifecycleProps::of(KeyLifecycleProps::createdAt($key), $key->last_used_at, $key->expires_at, $key->revoked_at, $now);

        return new self(
            id: $key->id,
            name: $key->name,
            prefix: $key->prefix,
            scopes: array_map(EnvironmentScopeProps::stored(...), $key->scopes),
            lifecycle: $lifecycle,
            revokeHref: $lifecycle->active() ? $revokeHref : null,
        );
    }

    /**
     * @return array{id: string, name: string, prefix: string, scopes: list<array{value: string, label: string, writes: bool}>, lifecycle: array<string, string|null>, revokeHref: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'prefix' => $this->prefix,
            'scopes' => array_map(static fn (EnvironmentScopeProps $scope): array => $scope->toArray(), $this->scopes),
            'lifecycle' => $this->lifecycle->toArray(),
            'revokeHref' => $this->revokeHref,
        ];
    }
}
