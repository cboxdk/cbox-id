<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Kernel\Audit\Enums\ActorType;

/**
 * A validated, active impersonation session. The session stores a loose array (a
 * serialization boundary), but everything in the app consumes this typed marker —
 * the acting principal is an {@see ActorType} enum, not a stringly-compared
 * `actor_type` value, so a caller asks {@see isAccountMember()} rather than matching
 * a magic string. Only ever constructed by {@see Impersonation::active()} after the
 * raw session data has been validated.
 */
final readonly class ImpersonationMarker
{
    public function __construct(
        public ActorType $actorType,
        public string $operator,
        public string $subject,
        public string $organizationId,
        public ?string $environmentKey,
        public ?string $reason,
        public int $startedAt,
    ) {}

    /**
     * Whether the acting principal is an account member (an env-admin) rather than a
     * platform operator — decides which control plane is restored on exit.
     */
    public function isAccountMember(): bool
    {
        return $this->actorType === ActorType::AccountMember;
    }
}
