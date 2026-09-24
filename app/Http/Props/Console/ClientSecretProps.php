<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use Carbon\CarbonImmutable;
use Cbox\Id\OAuthServer\ValueObjects\ClientSecretSummary;
use DateTimeInterface;

/**
 * One live secret of an app: never the secret, only the last four characters it ends in
 * and when things happened to it.
 *
 * `expiring` marks a secret a rotation has replaced and that still works until its
 * `expiresAt` — the overlap in which deployments move to the new one. `revokeHref` is null
 * when revoking it is not offered: the app's only live secret is replaced by rotating, not
 * revoked, because revoking it would switch the app off with nothing in its place.
 */
final readonly class ClientSecretProps implements Prop
{
    public function __construct(
        public string $id,
        public ?string $hint,
        public bool $expiring,
        public KeyLifecycleProps $lifecycle,
        public ?string $revokeHref,
    ) {}

    public static function from(ClientSecretSummary $secret, ?string $revokeHref, DateTimeInterface $now): self
    {
        $carbon = static fn (?DateTimeInterface $at): ?CarbonImmutable => $at === null ? null : CarbonImmutable::instance($at);

        return new self(
            id: $secret->id,
            hint: $secret->hint,
            expiring: $secret->isExpiring(),
            lifecycle: KeyLifecycleProps::of(
                $carbon($secret->createdAt),
                $carbon($secret->lastUsedAt),
                $carbon($secret->expiresAt),
                null,
                $now,
            ),
            revokeHref: $revokeHref,
        );
    }

    /**
     * @return array{id: string, hint: string|null, expiring: bool, lifecycle: array{status: string, statusLabel: string, createdAt: string|null, lastUsedAt: string|null, expiresAt: string|null}, revokeHref: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'hint' => $this->hint,
            'expiring' => $this->expiring,
            'lifecycle' => $this->lifecycle->toArray(),
            'revokeHref' => $this->revokeHref,
        ];
    }
}
