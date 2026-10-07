<?php

declare(strict_types=1);

namespace App\Platform\Actions\Idempotency;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * The first answer to an idempotent request, kept for a day so a retry gets it back.
 *
 * @property string $id
 * @property string $principal
 * @property string $idempotency_key
 * @property string $action
 * @property string $request_hash
 * @property int $status
 * @property array<mixed>|null $payload
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon $expires_at
 */
final class IdempotencyRecord extends Model
{
    use HasUlids;
    use Prunable;

    public const UPDATED_AT = null;

    protected $table = 'action_idempotency_records';

    protected $guarded = [];

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<', Carbon::now());
    }

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'payload' => 'array',
            'meta' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
