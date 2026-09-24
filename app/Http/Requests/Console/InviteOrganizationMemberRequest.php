<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\NewInvitation;
use App\Platform\OrgRoles;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An invitation to join an organization — from its own People page or from the environment
 * console's view of it. ONE request for both, because it is one invitation: two copies of
 * this class were how the two pages came to offer different roles.
 *
 * TWO KINDS OF ACCESS, as everywhere else: the membership role decides what the person may
 * administer here, and the ACCESS ROLES are what they can do inside this organization's
 * apps. The access roles are parked against the invitation and granted on acceptance.
 *
 * `client_id` + `return_to` name the app the invitation is for and where in it to land the
 * person afterwards. Only their SHAPE is checked here; whether that app may send people to
 * that address is the invitation service's question, asked of the organization.
 */
final class InviteOrganizationMemberRequest extends FormRequest
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
            // drift {@see OrgRoles} exists to prevent. Owner is not in it.
            'role' => ['required', OrgRoles::rule()],
            'accessRoles' => ['array'],
            'accessRoles.*' => ['string'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'return_to' => ['nullable', 'string', 'max:2048'],
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
     * Claims until the invitation service resolves them against what is assignable here.
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

    public function toInvitation(string $organizationId, Inviter $inviter): NewInvitation
    {
        return new NewInvitation(
            organizationId: $organizationId,
            email: $this->email(),
            role: $this->role(),
            inviter: $inviter,
            accessRoleIds: $this->accessRoleIds(),
            clientId: $this->filled('client_id') ? (string) $this->string('client_id') : null,
            returnTo: $this->filled('return_to') ? (string) $this->string('return_to') : null,
        );
    }
}
