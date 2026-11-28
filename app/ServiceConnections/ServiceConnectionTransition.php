<?php

declare(strict_types=1);

namespace App\ServiceConnections;

use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only recovery evidence. No TTL or cascading deletion: revoking the grant must
 * not erase the historical outcome. This is not a grant or a credential.
 *
 * @property string $id The approved plan's SHA-256, known before the write starts.
 * @property string $environment_id
 * @property string $connection_id
 * @property array<string, mixed> $payload
 */
class ServiceConnectionTransition extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('A service connection transition receipt is immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('A service connection transition receipt is immutable.');
        });
    }
}
