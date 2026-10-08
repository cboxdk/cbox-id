<?php

declare(strict_types=1);

namespace App\Platform\Install;

use App\Platform\Install\Contracts\SetupTokens;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * The setup token, kept in the database every replica shares — as a hash, with an expiry.
 *
 * WHY NOT THE DISK ANY MORE. It used to be a file under `storage/app/private`, which is a
 * fine secret store for one box and a broken one for two: on a deployment with several web
 * replicas the token existed on whichever pod happened to serve the first look at
 * `/first-run`, and the claim succeeded or failed depending on which pod the load balancer
 * picked for the submit. The database is the one store every replica already reads, and
 * unlike the cache it does not forget a value on a flush or an eviction.
 *
 * ONLY A HASH IS STORED. The token is 32 random bytes, so a plain SHA-256 is the right
 * hash — there is nothing to stretch, and a slow hash would only turn every guess into
 * CPU. A database dump, a backup or a read replica therefore holds nothing that claims
 * the platform. The cost is that the token cannot be read back: the operator gets it at
 * the moment it is minted — `cbox-id:setup-token` mints a fresh one and prints it, and an
 * opted-in log line carries the one minted on the first look — and never after.
 *
 * IT EXPIRES. A token minted for a deployment nobody claimed is a live credential for as
 * long as it sits there; bounding it means a copy that escaped (a terminal scrollback, a
 * log line) stops mattering on its own. An expired token is re-armed on the next look at
 * `/first-run`, and the command always mints a fresh one, so expiry never locks an
 * operator out.
 *
 * SINGLE USE: the claim spends it ({@see forget()}) once the platform is installed, and
 * the row is gone for every replica at once.
 */
final class DatabaseSetupTokens implements SetupTokens
{
    /** The one purpose a token serves today — the row key. */
    private const PURPOSE = 'first-run';

    private const TABLE = 'setup_tokens';

    public function __construct(
        private readonly LoggerInterface $log,
        /** How long a minted token stays valid, in minutes. */
        private readonly int $ttlMinutes,
        /**
         * Whether the token itself goes into the warning {@see arm()} writes.
         *
         * Off by default — the token is the whole of the authority to claim an unclaimed
         * platform, and a log shipped to a central aggregator hands it to everyone who
         * can read that. On for a single-container deploy where `docker logs` genuinely is
         * the operator's only view of the box: a deliberate choice by whoever knows where
         * those logs end up.
         */
        private readonly bool $logToken = false,
    ) {}

    public function arm(): void
    {
        $now = CarbonImmutable::now();

        // An expired token is no token: clear it so the insert below can re-arm.
        DB::table(self::TABLE)
            ->where('purpose', self::PURPOSE)
            ->where('expires_at', '<=', $now)
            ->delete();

        $token = self::mint();

        // insertOrIgnore, keyed by purpose: two replicas serving their first look at the
        // same moment cannot both arm, and the loser must not announce a token that the
        // winner's row does not hold.
        $armed = DB::table(self::TABLE)->insertOrIgnore($this->row($token, $now)) === 1;

        if (! $armed) {
            return;
        }

        // Deliberately at WARNING: this is the one moment an unclaimed platform is
        // reachable, and an operator scanning for it should not have to raise the log
        // level to find out that their deployment is waiting to be claimed. Written once,
        // at mint time, so a token does not accumulate copies in every rotated log.
        $this->log->warning(
            'Cbox ID is not installed yet. Open /first-run and paste the setup token to claim this deployment. '
            .'Print one with `php artisan cbox-id:setup-token` on any instance of this deployment. '
            .'It is the only thing standing between an empty platform and whoever finds it first, '
            .'and it stops working the moment the platform is claimed.',
            $this->logToken ? ['setup_token' => $token, 'expires_at' => $now->addMinutes($this->ttlMinutes)->toIso8601String()] : [],
        );
    }

    public function rotate(): string
    {
        $now = CarbonImmutable::now();
        $token = self::mint();

        DB::table(self::TABLE)->upsert(
            [$this->row($token, $now)],
            ['purpose'],
            ['token_hash', 'expires_at', 'created_at'],
        );

        return $token;
    }

    public function armed(): bool
    {
        return $this->liveHash() !== null;
    }

    public function expiresAt(): ?CarbonImmutable
    {
        $expiresAt = DB::table(self::TABLE)
            ->where('purpose', self::PURPOSE)
            ->where('expires_at', '>', CarbonImmutable::now())
            ->value('expires_at');

        return is_string($expiresAt) ? CarbonImmutable::parse($expiresAt) : null;
    }

    public function matches(string $candidate): bool
    {
        $candidate = trim($candidate);

        // An empty submission is not a guess worth a query.
        if ($candidate === '') {
            return false;
        }

        $stored = $this->liveHash();

        // No live token — never issued, spent, or expired — means nothing can match: an
        // absent secret is not a wildcard.
        if ($stored === null) {
            return false;
        }

        // Constant-time over two equal-length digests: how long the comparison takes says
        // nothing about how much of the guess was right.
        return hash_equals($stored, self::hash($candidate));
    }

    public function forget(): void
    {
        DB::table(self::TABLE)->where('purpose', self::PURPOSE)->delete();
    }

    /** The stored hash of the live token, or null when there is none. */
    private function liveHash(): ?string
    {
        $hash = DB::table(self::TABLE)
            ->where('purpose', self::PURPOSE)
            ->where('expires_at', '>', CarbonImmutable::now())
            ->value('token_hash');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /** @return array{purpose: string, token_hash: string, expires_at: CarbonImmutable, created_at: CarbonImmutable} */
    private function row(string $token, CarbonImmutable $now): array
    {
        return [
            'purpose' => self::PURPOSE,
            'token_hash' => self::hash($token),
            'expires_at' => $now->addMinutes($this->ttlMinutes),
            'created_at' => $now,
        ];
    }

    private static function mint(): string
    {
        return bin2hex(random_bytes(32));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
