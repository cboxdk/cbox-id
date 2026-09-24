<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use App\Platform\SupportAccess\ValueObjects\ActiveSupportSession;

/**
 * An open support session as a console row draws it — on a user's page and on an
 * organization's, the same shape, with the same End now.
 */
final readonly class SupportSessionProps implements Prop
{
    public function __construct(
        public string $id,
        public string $user,
        public string $userHref,
        public string $organization,
        public string $app,
        public ?string $appUrl,
        public string $reason,
        public ?string $startedBy,
        public string $expiresIn,
        public string $expiresAt,
        public string $endHref,
    ) {}

    public static function from(ActiveSupportSession $session): self
    {
        return new self(
            id: $session->id,
            user: $session->targetLabel ?? $session->targetUserId,
            userHref: route('environment.users.show', $session->targetUserId),
            organization: $session->organizationName ?? $session->organizationId,
            app: $session->appName ?? $session->clientId,
            appUrl: $session->launchUrl,
            reason: $session->reason,
            startedBy: $session->actorLabel,
            expiresIn: $session->expiresAt->diffForHumans(),
            expiresAt: $session->expiresAt->toIso8601String(),
            endHref: route('environment.support-sessions.end', $session->id),
        );
    }

    /**
     * @param  list<ActiveSupportSession>  $sessions
     * @return list<self>
     */
    public static function list(array $sessions): array
    {
        return array_map(self::from(...), $sessions);
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user' => $this->user,
            'userHref' => $this->userHref,
            'organization' => $this->organization,
            'app' => $this->app,
            'appUrl' => $this->appUrl,
            'reason' => $this->reason,
            'startedBy' => $this->startedBy,
            'expiresIn' => $this->expiresIn,
            'expiresAt' => $this->expiresAt,
            'endHref' => $this->endHref,
        ];
    }
}
