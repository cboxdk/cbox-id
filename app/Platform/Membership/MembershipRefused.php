<?php

declare(strict_types=1);

namespace App\Platform\Membership;

use RuntimeException;

/** A membership change {@see MembershipLifecycle} would not make, with the sentence to show. */
final class MembershipRefused extends RuntimeException
{
    private function __construct(public readonly MembershipRefusalReason $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notAMember(): self
    {
        return new self(MembershipRefusalReason::NotAMember, 'That person is not a member of this organization.');
    }

    public static function alreadyOwner(): self
    {
        return new self(MembershipRefusalReason::AlreadyOwner, 'That person already owns this organization.');
    }

    public static function notTheOwner(): self
    {
        return new self(MembershipRefusalReason::NotTheOwner, 'Only the owner can do that.');
    }

    public static function lastOwner(): self
    {
        return new self(
            MembershipRefusalReason::LastOwner,
            'You are the only owner. Transfer ownership to someone else first — or delete the organization.',
        );
    }

    public static function nameMismatch(): self
    {
        return new self(MembershipRefusalReason::NameMismatch, 'Type the organization\'s name exactly as shown to confirm.');
    }

    public static function ownsProducts(): self
    {
        return new self(
            MembershipRefusalReason::OwnsProducts,
            'This organization owns identity providers on this platform. Close its projects under Workspace › Projects first.',
        );
    }
}
