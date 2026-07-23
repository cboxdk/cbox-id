<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;

/**
 * Request-scoped holder for the authenticated subject, its platform session, and
 * the active organization. Populated by the Authenticate middleware and shared
 * with views; components read it instead of reaching for the framework directly.
 */
final class CurrentUser
{
    private ?Subject $subject = null;

    private ?Session $session = null;

    private ?Organization $organization = null;

    private ?MembershipRole $role = null;

    public function set(Subject $subject, Session $session, ?Organization $organization, ?MembershipRole $role = null): void
    {
        $this->subject = $subject;
        $this->session = $session;
        $this->organization = $organization;
        $this->role = $role;
    }

    public function role(): ?MembershipRole
    {
        return $this->role;
    }

    public function isOwner(): bool
    {
        return $this->role === MembershipRole::Owner;
    }

    public function isAdmin(): bool
    {
        return $this->role !== null && $this->role->canManageOrganization();
    }

    public function check(): bool
    {
        return $this->subject !== null;
    }

    public function subject(): ?Subject
    {
        return $this->subject;
    }

    public function id(): string
    {
        return $this->subject !== null ? $this->subject->id : '';
    }

    public function name(): string
    {
        if ($this->subject === null) {
            return 'Account';
        }

        return $this->subject->name ?? $this->subject->email ?? 'Account';
    }

    public function email(): ?string
    {
        return $this->subject?->email;
    }

    public function session(): ?Session
    {
        return $this->session;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function organizationId(): ?string
    {
        return $this->organization?->id;
    }
}
