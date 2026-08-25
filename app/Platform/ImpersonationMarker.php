<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Kernel\Audit\Enums\ActorType;

/**
 * A validated, active impersonation session. The session stores a loose array (a
 * serialization boundary), but everything in the app consumes this typed marker —
 * the acting principal is an {@see ActorType} enum, not a stringly-compared
 * `actor_type` value, so a caller asks {@see isMembership()} rather than matching
 * a magic string. Only ever constructed by {@see Impersonation::active()} after the
 * raw session data has been validated.
 */
final readonly class ImpersonationMarker
{
    /**
     * @param  SuspendedIdentity  $suspended  What the browser was signed in as before this
     *                                        started, put aside for the duration. See
     *                                        {@see Impersonation::start()} — restore hints,
     *                                        never authorizations: each is re-bound to the
     *                                        freshly-resolved principal on the way back.
     */
    public function __construct(
        public ActorType $actorType,
        public string $operator,
        public string $subject,
        public ?string $organizationId,
        public ?string $environmentKey,
        public ?string $reason,
        public int $startedAt,
        public SuspendedIdentity $suspended = new SuspendedIdentity,
    ) {}

    /**
     * Whether the acting principal is an account member (an env-admin) rather than a
     * platform operator — decides which control plane is restored on exit.
     */
    public function isMembership(): bool
    {
        return $this->actorType === ActorType::OrganizationMember;
    }

    /**
     * The flat session representation (the serialization boundary). Owning both
     * directions here keeps {@see Impersonation::start()} from hand-writing a literal
     * that must stay in sync with the reader.
     *
     * @return array{actor_type: string, operator: string, subject: string, org: string|null, env: string|null, reason: string|null, started_at: int, suspended: array{subject_session: string|null}}
     */
    public function toSession(): array
    {
        return [
            'actor_type' => $this->actorType->value,
            'operator' => $this->operator,
            'subject' => $this->subject,
            'org' => $this->organizationId,
            'env' => $this->environmentKey,
            'reason' => $this->reason,
            'started_at' => $this->startedAt,
            'suspended' => $this->suspended->toSession(),
        ];
    }
}
