<?php

declare(strict_types=1);

namespace App\Platform\Invitations\Exceptions;

use App\Platform\Invitations\Enums\InvitationRefusalReason;
use App\Platform\OrgRoles;
use RuntimeException;

/**
 * An invitation the service would not send, re-send or withdraw — with the reason, and the
 * sentence a person should read.
 *
 * The sentences live HERE rather than at each caller, because there are several callers and
 * one set of rules: a refusal that reads differently on the People page and on the
 * environment console is how people learn the two are different products.
 */
final class InvitationRefused extends RuntimeException
{
    private function __construct(public readonly InvitationRefusalReason $reason, string $message)
    {
        parent::__construct($message);
    }

    public function field(): string
    {
        return $this->reason->field();
    }

    public static function alreadyMember(): self
    {
        return new self(InvitationRefusalReason::AlreadyMember, 'That person is already a member of this organization.');
    }

    public static function roleNotOffered(): self
    {
        return new self(
            InvitationRefusalReason::RoleNotOffered,
            OrgRoles::message().' Ownership is handed over with "Transfer ownership", never by invitation.',
        );
    }

    public static function accessRoleConflict(string $message): self
    {
        return new self(InvitationRefusalReason::AccessRoleConflict, $message);
    }

    public static function returnWithoutApp(): self
    {
        return new self(
            InvitationRefusalReason::ReturnWithoutApp,
            'A return address needs the app it belongs to. Send the app\'s client ID with it.',
        );
    }

    public static function unknownApp(): self
    {
        return new self(
            InvitationRefusalReason::UnknownApp,
            'No app with that client ID is available to this organization.',
        );
    }

    public static function returnToMalformed(): self
    {
        return new self(
            InvitationRefusalReason::ReturnToMalformed,
            'The return address must be a full https:// URL (http:// only for localhost).',
        );
    }

    public static function returnToNotRegistered(string $app): self
    {
        return new self(
            InvitationRefusalReason::ReturnToNotRegistered,
            'The return address must be on one of '.$app.'\'s registered redirect URI origins.',
        );
    }

    public static function notPending(): self
    {
        return new self(
            InvitationRefusalReason::NotPending,
            'That invitation has already been accepted, withdrawn or has expired.',
        );
    }

    public static function tooSoon(int $seconds): self
    {
        return new self(
            InvitationRefusalReason::TooSoon,
            'Already sent. Try again in '.$seconds.' seconds — and check their spam folder in the meantime.',
        );
    }

    public static function mailFailed(bool $invitationKept): self
    {
        return new self(
            InvitationRefusalReason::MailFailed,
            $invitationKept
                ? 'That invitation could not be sent — the mail server refused it. It is still listed below; try again in a moment.'
                : 'We could not send that invitation — the mail server refused it. Nothing was created; try again, or check the deployment\'s mail configuration.',
        );
    }
}
