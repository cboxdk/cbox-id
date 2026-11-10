<?php

declare(strict_types=1);

namespace App\Platform\Membership;

use App\Platform\Actions\ActionMessage;
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

    /** An invitation not yet accepted, or a suspended member: ownership needs somebody who can act on it. */
    public static function notActive(): self
    {
        return new self(
            MembershipRefusalReason::NotActive,
            'That person has not accepted their invitation yet, or is suspended. Only an active member can become the owner.',
        );
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

    public function sourceMessage(): ActionMessage
    {
        return new ActionMessage(match ($this->reason) {
            MembershipRefusalReason::NotAMember => 'console.that_person_is_not_a_member_of_this_organization_5397f3d7',
            MembershipRefusalReason::NotActive => 'console.that_person_has_not_accepted_their_invitation_yet_or_is_3c9ff95b',
            MembershipRefusalReason::AlreadyOwner => 'console.that_person_already_owns_this_organization_04ec4fe8',
            MembershipRefusalReason::NotTheOwner => 'console.only_the_owner_can_do_that_6e95698b',
            MembershipRefusalReason::LastOwner => 'console.you_are_the_only_owner_transfer_ownership_to_someone_el_1e7b6195',
            MembershipRefusalReason::NameMismatch => 'console.type_the_organization_s_name_exactly_as_shown_to_confir_f021127d',
            MembershipRefusalReason::OwnsProducts => 'console.this_organization_owns_identity_providers_on_this_platf_f47c5479',
        });
    }
}
