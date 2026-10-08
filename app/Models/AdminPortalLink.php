<?php

declare(strict_types=1);

namespace App\Models;

use App\Platform\AdminPortal;
use App\Platform\Enums\PortalScope;
use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A short-lived, single-use Admin Portal setup link. An entitled org admin mints
 * one and hands it to an external IT admin, who redeems it to configure what it covers
 * for that one org — SSO, directory sync, domains, log streams, certificate renewal —
 * with no platform account.
 *
 * Only a SHA-256 hash of the random token is stored; the plaintext is shown to
 * the minting admin exactly once and is never retrievable again. A link is
 * redeemable while it is neither expired, already consumed nor revoked — and a revoked
 * link's open setup session ends on its next request.
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
 * @property list<string>|null $intents the {@see PortalScope} it covers, as stored
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at the moment it was redeemed — it is single-use
 * @property Carbon|null $completed_at the moment its setup was finished
 * @property Carbon|null $revoked_at the moment it was withdrawn; redemption and its open setup session both end
 * @property string|null $revoked_by who withdrew it
 * @property Carbon|null $created_at
 * @property string $created_by
 * @property string|null $emailed_to the IT contact it was mailed to, when the console sent it
 */
final class AdminPortalLink extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    protected $guarded = [];

    /** Waiting to be opened. */
    public const string PENDING = 'pending';

    /** Opened, and its setup session may still be open. */
    public const string IN_USE = 'in_use';

    /** Its setup was finished. */
    public const string COMPLETED = 'completed';

    /** Never opened before it expired, or opened and its setup session has run out. */
    public const string EXPIRED = 'expired';

    /** Withdrawn. */
    public const string REVOKED = 'revoked';

    /**
     * Whether the link may still be redeemed right now.
     */
    public function isRedeemable(): bool
    {
        return $this->revoked_at === null && $this->consumed_at === null && $this->expires_at->isFuture();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Where the link stands, one word for it — withdrawn and finished outrank everything,
     * because they are final; an opened link is in use for as long as the setup session it
     * opened can last ($sessionMinutes, {@see AdminPortal::sessionMinutes()}).
     */
    public function status(int $sessionMinutes): string
    {
        return match (true) {
            $this->revoked_at !== null => self::REVOKED,
            $this->completed_at !== null => self::COMPLETED,
            $this->consumed_at !== null => $this->consumed_at->copy()->addMinutes($sessionMinutes)->isFuture() ? self::IN_USE : self::EXPIRED,
            $this->expires_at->isFuture() => self::PENDING,
            default => self::EXPIRED,
        };
    }

    /**
     * What the link may set up — null when the stored list names nothing this deployment
     * knows, which opens nothing ({@see PortalScope::fromStored()}).
     */
    public function portalScope(): ?PortalScope
    {
        return PortalScope::fromStored($this->intents);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'intents' => 'array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'completed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
