<?php

declare(strict_types=1);

namespace App\Models;

use App\Platform\AdminPortal;
use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
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
 * ENVIRONMENT-OWNED, like every other credential-bearing row. The token hash is the
 * only thing redemption matches on, so without the hard outer scope the lookup in
 * {@see AdminPortal::redeem()} was environment-blind: a link minted on
 * one environment's host could be redeemed on ANY host, and the SSO connection,
 * verified domain or SCIM directory the redeemer created was then stamped with the
 * REDEEMING environment. That let an operator of a second environment claim
 * unclaimed domains, stand up connections, and mint a SCIM bearer token inside their
 * own environment off a link they were handed for another. (Not a login hijack: an
 * already-claimed domain still throws, and taking over a domain you do not control
 * still requires the DNS TXT record.) Scoping the model closes the lookup itself, so
 * the token is meaningless anywhere but the environment that issued it.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $organization_id
 * @property string $scope
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string $created_by
 */
final class AdminPortalLink extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
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
