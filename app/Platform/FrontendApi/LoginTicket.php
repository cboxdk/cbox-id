<?php

declare(strict_types=1);

namespace App\Platform\FrontendApi;

use Carbon\CarbonInterface;
use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * A completed credential check, waiting to be turned into a session by `/authorize`.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $token_hash
 * @property string $publishable_key_id
 * @property string $subject_id
 * @property string $stage
 * @property int $attempts
 * @property array<int, string> $amr
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $redeemed_at
 */
class LoginTicket extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;
    use Prunable;

    protected $table = 'frontend_login_tickets';

    protected $guarded = [];

    /**
     * Tickets nobody redeemed, an hour after they stopped being redeemable.
     *
     * THE HIGHEST-VOLUME TABLE THIS CHANNEL ADDS — one row per embedded sign-in attempt,
     * each naming a subject. Expired rows are harmless in themselves, they cannot be
     * redeemed; a table that only ever grows is the thing somebody finds during an incident
     * and cannot read. The hour of slack is so a row that has just expired is still there
     * to be looked at while the person is still on the phone.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<', now()->subHour());
    }

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
