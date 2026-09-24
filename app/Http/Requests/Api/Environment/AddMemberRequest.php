<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Environment;

use App\Platform\OrgRoles;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/organizations/{id}/members` — an EXISTING user, straight in, no invitation.
 *
 * The tier is one this deployment offers ({@see OrgRoles}); `owner` is not one of them —
 * ownership moves with `transfer-ownership`, never by assignment.
 */
final class AddMemberRequest extends FormRequest
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
            'user_id' => ['required', 'string', 'max:64'],
            'role' => ['sometimes', OrgRoles::rule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['role' => OrgRoles::message().' Ownership moves with transfer-ownership.'];
    }

    public function userId(): string
    {
        return $this->string('user_id')->toString();
    }

    public function role(): MembershipRole
    {
        return $this->enum('role', MembershipRole::class) ?? MembershipRole::Member;
    }
}
