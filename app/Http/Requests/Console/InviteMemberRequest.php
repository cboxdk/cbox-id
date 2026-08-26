<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** An invitation into the account's administrator roster. */
final class InviteMemberRequest extends FormRequest
{
    /**
     * WHO may invite is a capability question, asked in the controller where the acting
     * organization is resolved — so the check and the write cannot disagree about which
     * organization they are talking about.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>|array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            // ASSIGNABLE roles only. `MembershipRole` also has Owner, which is transferred
            // rather than granted — an invitation that could mint one would hand the
            // account away without the current owner doing anything.
            'role' => ['required', Rule::in(array_map(
                static fn (MembershipRole $role): string => $role->value,
                MembershipRole::assignable(),
            ))],
        ];
    }

    /**
     * The refusal NAMES WHAT IS ACCEPTED.
     *
     * "The selected role is invalid" is what the framework says, and it tells somebody
     * staring at a select they never touched precisely nothing. Built from the assignable
     * set rather than written out, so a role added to the enum appears here the same day.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'Choose one of: '.implode(', ', array_map(
                static fn (MembershipRole $role): string => $role->label(),
                MembershipRole::assignable(),
            )).'.',
            'role.required' => 'Choose one of: '.implode(', ', array_map(
                static fn (MembershipRole $role): string => $role->label(),
                MembershipRole::assignable(),
            )).'.',
        ];
    }

    public function email(): string
    {
        return trim((string) $this->string('email'));
    }

    public function role(): MembershipRole
    {
        return MembershipRole::from((string) $this->string('role'));
    }
}
