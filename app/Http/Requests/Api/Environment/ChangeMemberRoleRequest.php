<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Environment;

use App\Platform\OrgRoles;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /v1/organizations/{id}/members/{userId}` — change a member's tier. Making someone
 * the owner is `transfer-ownership`, so `owner` is refused here like any other value this
 * deployment does not offer.
 */
final class ChangeMemberRoleRequest extends FormRequest
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
            'role' => ['required', OrgRoles::rule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['role' => OrgRoles::message().' Ownership moves with transfer-ownership.'];
    }

    /** Safe to parse: the rule above admits only cases of the enum. */
    public function role(): MembershipRole
    {
        return MembershipRole::from($this->string('role')->toString());
    }
}
