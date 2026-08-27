<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Platform\OrgRoles;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Inviting somebody to an organization's own roster.
 *
 * TWO KINDS OF ACCESS, as everywhere else: the CONSOLE ACCESS decides what they may
 * administer here, and the ACCESS ROLES are what they can do inside this organization's
 * apps. The access roles are parked against the invitation and granted the moment it is
 * accepted, so there is no separate assignment step after somebody joins.
 */
final class InviteDirectoryMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:190'],
            // Enum-authoritative rather than a hand-copied `in:` list, which is exactly the
            // drift {@see OrgRoles} exists to prevent.
            'role' => ['required', OrgRoles::rule()],
            'accessRoles' => ['array'],
            'accessRoles.*' => ['string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['role' => OrgRoles::message()];
    }

    public function email(): string
    {
        return trim((string) $this->string('email'));
    }

    /**
     * Safe to parse rather than tryFrom: the rule above is derived from the same assignable
     * set, so a value that reached here is a case of the enum.
     */
    public function role(): MembershipRole
    {
        return MembershipRole::from((string) $this->string('role'));
    }

    /**
     * Claims until the controller resolves them against what is assignable here.
     *
     * @return list<string>
     */
    public function accessRoleIds(): array
    {
        return array_values(array_unique(array_filter(
            (array) $this->input('accessRoles', []),
            'is_string',
        )));
    }
}
