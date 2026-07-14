<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A short-lived, single-use Admin Portal setup link. An entitled org admin mints
 * one and hands it to an external IT admin, who redeems it to configure that one
 * org's SSO/SCIM — with no platform account.
 *
 * Only a SHA-256 hash of the random token is stored; the plaintext is shown to
 * the minting admin exactly once and is never retrievable again. A link is
 * redeemable while it is neither expired nor already consumed.
 *
 * This is an APP table — the app owns the concept; it is not a package model.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $scope
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string $created_by
 */
final class AdminPortalLink extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * Whether the link may still be redeemed right now.
     */
    public function isRedeemable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
