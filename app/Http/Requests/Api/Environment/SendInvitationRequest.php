<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Environment;

use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\NewInvitation;
use App\Platform\OrgRoles;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/organizations/{id}/invitations`.
 *
 * `role` is the built-in tier; `roles` are access roles — your app's manifest roles, by id,
 * or by `key` when `client_id` names the app that declared them — granted the moment the
 * invitation is accepted. `return_to` sends the person back into your app afterwards and
 * must sit on one of that app's registered redirect-URI origins. `inviter_name` is who the
 * mail says the invitation is from; left out, it is the app's name (or the environment's).
 */
final class SendInvitationRequest extends FormRequest
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
            'role' => ['sometimes', OrgRoles::rule()],
            'roles' => ['sometimes', 'array', 'max:50'],
            'roles.*' => ['string', 'max:190'],
            'client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'return_to' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'inviter_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['role' => OrgRoles::message().' Ownership moves with transfer-ownership, never by invitation.'];
    }

    public function email(): string
    {
        return trim($this->string('email')->toString());
    }

    public function role(): MembershipRole
    {
        return $this->enum('role', MembershipRole::class) ?? MembershipRole::Member;
    }

    /**
     * The role references as sent — ids, or manifest keys of `client_id`'s app.
     *
     * @return list<string>
     */
    public function roleReferences(): array
    {
        return array_values(array_unique(array_filter(
            (array) $this->input('roles', []),
            static fn (mixed $ref): bool => is_string($ref) && $ref !== '',
        )));
    }

    public function clientId(): ?string
    {
        return $this->filled('client_id') ? trim($this->string('client_id')->toString()) : null;
    }

    public function returnTo(): ?string
    {
        return $this->filled('return_to') ? trim($this->string('return_to')->toString()) : null;
    }

    public function inviterName(): ?string
    {
        return $this->filled('inviter_name') ? trim($this->string('inviter_name')->toString()) : null;
    }

    /**
     * @param  list<string>  $roleIds  the references, resolved to ids and checked
     */
    public function toInvitation(string $organizationId, Inviter $inviter, array $roleIds): NewInvitation
    {
        return new NewInvitation(
            organizationId: $organizationId,
            email: $this->email(),
            role: $this->role(),
            inviter: $inviter,
            accessRoleIds: $roleIds,
            clientId: $this->clientId(),
            returnTo: $this->returnTo(),
        );
    }
}
