<?php

declare(strict_types=1);

namespace App\Platform\FrontendApi;

use Carbon\CarbonInterface;
use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A completed credential check, waiting to be turned into a session by `/authorize`.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $token_hash
 * @property string $publishable_key_id
 * @property string $subject_id
 * @property array<int, string> $amr
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $redeemed_at
 */
class LoginTicket extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    protected $table = 'frontend_login_tickets';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amr' => 'array',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }
}
