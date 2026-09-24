<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use App\Platform\Enums\KeyStatus;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a machine credential stands and when things happened to it — the half of a key
 * row that is the same for every kind of key.
 *
 * ONE SHAPE FOR BOTH KEY PAGES. The account key page showed a revoked badge and a relative
 * last-used time; the environment key page showed neither, listed revoked keys exactly
 * like live ones and still offered to revoke them. Two pages that answer the same question
 * for two kinds of credential now answer it from the same object, so they cannot drift
 * again.
 *
 * Every timestamp crosses as ISO 8601 and is formatted in the browser — relative AND
 * absolute: "3 minutes ago" computed on the server is wrong the moment the page sits
 * open, and a relative time alone cannot be compared against a deploy log.
 */
final readonly class KeyLifecycleProps implements Prop
{
    public function __construct(
        public KeyStatus $status,
        public ?string $createdAt,
        public ?string $lastUsedAt,
        public ?string $expiresAt,
    ) {}

    public static function of(
        ?CarbonInterface $createdAt,
        ?CarbonInterface $lastUsedAt,
        ?CarbonInterface $expiresAt,
        ?CarbonInterface $revokedAt,
        DateTimeInterface $now,
    ): self {
        return new self(
            status: KeyStatus::of($revokedAt, $expiresAt, $now),
            createdAt: $createdAt?->toIso8601String(),
            lastUsedAt: $lastUsedAt?->toIso8601String(),
            expiresAt: $expiresAt?->toIso8601String(),
        );
    }

    /**
     * When a key row was created, narrowed rather than cast.
     *
     * The framework's key models declare every column they own except the timestamps
     * Eloquent adds, so the attribute arrives untyped; a value that is not a date is a row
     * to show without one, not a date to invent.
     */
    public static function createdAt(Model $key): ?CarbonInterface
    {
        $value = $key->getAttribute('created_at');

        return $value instanceof CarbonInterface ? $value : null;
    }

    public function active(): bool
    {
        return $this->status === KeyStatus::Active;
    }

    /**
     * @return array{status: string, statusLabel: string, createdAt: string|null, lastUsedAt: string|null, expiresAt: string|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'createdAt' => $this->createdAt,
            'lastUsedAt' => $this->lastUsedAt,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
