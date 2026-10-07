<?php

declare(strict_types=1);

namespace App\Platform\Actions;

/**
 * What an action produced, in two forms: the domain value a console door redirects with
 * (the API it just made), and the presented payload a machine door returns (`data`).
 *
 * The payload is what idempotent replay stores and returns, so it must be plain data.
 */
final readonly class ActionResult
{
    /**
     * @param  array<mixed>|null  $payload  Null for "nothing to say" (204).
     * @param  array<string, mixed>|null  $meta  A list's `meta` (cursor, has_more).
     */
    private function __construct(
        public mixed $value,
        public ?array $payload,
        public ?int $status,
        public ?array $meta = null,
        public bool $replayed = false,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function item(mixed $value, array $payload, ?int $status = null): self
    {
        return new self($value, $payload, $status);
    }

    /**
     * @param  list<mixed>  $payload
     * @param  array<string, mixed>  $meta
     */
    public static function page(mixed $value, array $payload, array $meta): self
    {
        return new self($value, $payload, 200, $meta);
    }

    /**
     * A whole list, unpaged: one a workspace holds a handful of (its projects, its pending
     * invitations), where a cursor would be ceremony around a single page.
     *
     * @param  list<mixed>  $payload
     */
    public static function items(mixed $value, array $payload): self
    {
        return new self($value, $payload, 200);
    }

    public static function none(mixed $value = null): self
    {
        return new self($value, null, 204);
    }

    /**
     * The first answer to an idempotent request, as stored: the payload only, marked so
     * the door can say it is a replay.
     *
     * @param  array<mixed>|null  $payload
     * @param  array<string, mixed>|null  $meta
     */
    public static function replayed(?array $payload, int $status, ?array $meta, bool $replay = true): self
    {
        return new self(null, $payload, $status, $meta, $replay);
    }
}
