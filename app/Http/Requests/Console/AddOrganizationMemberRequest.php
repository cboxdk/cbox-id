<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Platform\OrgRoles;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding an existing user of this environment to an organization.
 *
 * TWO DIFFERENT KINDS OF ACCESS, and the form asks for both because they answer different
 * questions. The membership ROLE governs who administers the organization and how support
 * impersonation treats them; the ACCESS ROLES are what the person can do inside the apps.
 * Conflating them is how somebody ends up an owner in order to read a report.
 */
final class AddOrganizationMemberRequest extends FormRequest
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
