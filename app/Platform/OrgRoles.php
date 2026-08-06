<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * The org-membership roles this console offers, and the single place an untrusted
 * role string becomes a {@see MembershipRole}.
 *
 * The framework enum carries five cases; the console deliberately offers three.
 * Developer and Viewer are technical-plane roles with no meaning on an
 * organization's member roster here, so the restriction is this host's product
 * decision — which is why it lives app-side rather than on the packaged enum, and
 * mirrors {@see MembershipRole::assignable()} on the account plane.
 *
 * A public Livewire prop is attacker-controlled: the wire request carries the whole
 * component state, so a `<select>` constrains a browser and nothing else. Every role
 * reaching {@see Memberships::add()}, `changeRole()` or {@see Invitations::invite()}
 * therefore passes through {@see self::rule()} (form fields — a field error naming
 * the accepted roles) or {@see self::parse()} (JS-invoked actions, which have no
 * field to report into — a refusal), never a bare `MembershipRole::from()`.
 */
final class OrgRoles
{
    /**
     * The roles the console may assign, highest last so the `<select>`s read the way
     * they always have (Member, Admin, Owner).
     *
     * @return list<MembershipRole>
     */
    public static function assignable(): array
    {
        return [MembershipRole::Member, MembershipRole::Admin, MembershipRole::Owner];
    }

    /**
     * The validation rule for a role field. Enum-authoritative, so a case added to
     * (or removed from) {@see MembershipRole} cannot leave a hand-written `in:` list
     * silently behind.
     */
    public static function rule(): Enum
    {
        return Rule::enum(MembershipRole::class)->only(self::assignable());
    }

    /**
     * The message a refused role gets. Names what IS accepted — the default
     * "selected value is invalid" tells an admin nothing about how to proceed.
     */
    public static function message(): string
    {
        return 'Choose one of: '.implode(', ', array_map(
            static fn (MembershipRole $role): string => $role->label(),
            self::assignable(),
        )).'.';
    }

    /**
     * Deny-by-default parse of an untrusted role string. An unknown value and a role
     * this console does not assign both read as null — never coerced to a default,
     * because the result feeds the last-owner guard and the isOwner/isAdmin checks.
     */
    public static function parse(string $role): ?MembershipRole
    {
        $parsed = MembershipRole::tryFrom($role);

        return $parsed !== null && in_array($parsed, self::assignable(), true) ? $parsed : null;
    }
}
