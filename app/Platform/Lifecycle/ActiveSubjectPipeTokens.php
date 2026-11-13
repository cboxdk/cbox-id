<?php

declare(strict_types=1);

namespace App\Platform\Lifecycle;

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\UserStatus;
use Cbox\Id\OAuthServer\JwtTokenIntrospector;
use Cbox\Id\Pipes\Contracts\PipeTokens;
use Cbox\Id\Pipes\Exceptions\PipeLeaseDenied;
use Cbox\Id\Pipes\Models\PipeConnection;
use Cbox\Id\Pipes\ValueObjects\PipeAccessToken;

/**
 * A deactivated person's connected accounts are not handed out, and not kept fresh.
 *
 * Deactivation revokes the person's own grants with Cbox ID. It did not touch the
 * third-party tokens they connected through a pipe (GitHub, Google, HubSpot), and the lease
 * never asked whether the person was still active: an app the environment granted the pipe
 * went on leasing a leaver's provider token, and the refresh sweep went on renewing it for
 * as long as the provider honoured the refresh token.
 *
 * The lease is refused with the pipe's one uniform refusal (no signal about the person),
 * and the sweep leaves the connection as it is. Asked of the subject on every call rather
 * than written onto the connection at deactivation, so reactivating the person restores
 * their connections without asking them to connect again.
 */
final readonly class ActiveSubjectPipeTokens implements PipeTokens
{
    public function __construct(private PipeTokens $inner, private Subjects $subjects) {}

    public function lease(string $provider, string $userId, string $clientId, string $purpose): PipeAccessToken
    {
        if ($this->deactivated($userId)) {
            throw PipeLeaseDenied::make();
        }

        return $this->inner->lease($provider, $userId, $clientId, $purpose);
    }

    public function refresh(string $connectionId, bool $force = false, bool $wait = true): PipeConnection
    {
        return $this->inactiveOwner($connectionId) ?? $this->inner->refresh($connectionId, $force, $wait);
    }

    public function refreshIfExpiring(string $connectionId, int $withinSeconds): PipeConnection
    {
        return $this->inactiveOwner($connectionId) ?? $this->inner->refreshIfExpiring($connectionId, $withinSeconds);
    }

    /** The connection, untouched, when its person is no longer active; null to go on. */
    private function inactiveOwner(string $connectionId): ?PipeConnection
    {
        $connection = PipeConnection::query()->whereKey($connectionId)->first();

        return $connection !== null && $this->deactivated($connection->user_id) ? $connection : null;
    }

    /**
     * A known account that is no longer Active (disabled or locked). Not `isActive()`, which
     * also answers false for an id with no account: the introspector draws the same line
     * ({@see JwtTokenIntrospector}), and a lease names the person a
     * signed token or a granted app vouched for.
     */
    private function deactivated(string $userId): bool
    {
        $subject = $this->subjects->find($userId);

        if ($subject === null) {
            return false;
        }

        return $subject->status !== null ? $subject->status !== UserStatus::Active : ! $this->subjects->isActive($userId);
    }
}
