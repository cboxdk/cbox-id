<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Platform\OrgRoles;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An invitation to join an organization.
 *
 * The same two kinds of access as adding an existing member — the difference is only that
 * the invitee has to accept, so the access roles are PARKED against this invitation and
 * applied on acceptance rather than granted now.
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

    public function role(): MembershipRole
    {
        return MembershipRole::from((string) $this->string('role'));
    }

    /**
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
