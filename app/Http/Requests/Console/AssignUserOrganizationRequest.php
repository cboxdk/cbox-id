<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Platform\OrgRoles;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding one end-user to an organization, from that user's own detail page.
 *
 * The mirror image of {@see AddOrganizationMemberRequest}: the same two kinds of access,
 * asked from the other side of the relationship. Which organization is a FIELD here rather
 * than a route parameter, so the controller has to prove it is in this environment and
 * still taking members before it writes anything.
 */
final class AssignUserOrganizationRequest extends FormRequest
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
            'organization' => ['required', 'string'],
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
        return [
            'role' => OrgRoles::message(),
            'organization.required' => 'Choose an organization.',
        ];
    }

    public function organizationId(): string
    {
        return (string) $this->string('organization');
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
     * Claims until the controller resolves them against what is assignable in that org.
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
