<?php

declare(strict_types=1);

namespace App\Platform\Invitations\Enums;

/**
 * Why an invitation was not sent, re-sent or withdrawn.
 *
 * A REASON rather than a message, so a test can assert which rule refused — two refusals
 * throwing the same exception class is exactly how a guard gets deleted with its test still
 * green — and so every surface that invites (three console pages today, the management API
 * tomorrow) reports the same refusal against the same field.
 */
enum InvitationRefusalReason: string
{
    case AlreadyMember = 'already_member';
    case RoleNotOffered = 'role_not_offered';
    case AccessRoleConflict = 'access_role_conflict';
    case ReturnWithoutApp = 'return_without_app';
    case UnknownApp = 'unknown_app';
    case ReturnToMalformed = 'return_to_malformed';
    case ReturnToNotRegistered = 'return_to_not_registered';
    case NotPending = 'not_pending';
    case TooSoon = 'too_soon';
    case MailFailed = 'mail_failed';

    /** The form field the refusal is reported against. */
    public function field(): string
    {
        return match ($this) {
            self::AlreadyMember, self::MailFailed => 'email',
            self::RoleNotOffered => 'role',
            self::AccessRoleConflict => 'accessRoles',
            self::ReturnWithoutApp, self::UnknownApp => 'client_id',
            self::ReturnToMalformed, self::ReturnToNotRegistered => 'return_to',
            self::NotPending, self::TooSoon => 'invitation',
        };
    }
}
