<?php

declare(strict_types=1);

namespace App\Platform\Membership;

/**
 * Why a change to who belongs to an organization — or who owns it — was refused.
 *
 * A reason rather than a message, so a test can say WHICH rule refused: the last-owner
 * guard and "not a member" would otherwise throw the same thing and a test could pass with
 * the guard it is about deleted.
 */
enum MembershipRefusalReason: string
{
    case NotAMember = 'not_a_member';
    case AlreadyOwner = 'already_owner';
    case NotTheOwner = 'not_the_owner';
    case LastOwner = 'last_owner';
    case NameMismatch = 'name_mismatch';
    case OwnsProducts = 'owns_products';
}
