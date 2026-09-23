<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Carbon\CarbonImmutable;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Minting a management-plane API key.
 *
 * The role is validated against the ASSIGNABLE set, not against every case of the enum:
 * the roles a person may hand out are a narrower list than the roles that exist, and a
 * key is a role somebody is handing out.
 */
final class IssueApiKeyRequest extends FormRequest
{
    /**
     * Whether this member may mint keys at all is a capability question, asked in the
     * controller before the step-up — so a refusal arrives before a password prompt
     * rather than after it.
     */
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
            'name' => ['required', 'string', 'max:120'],
            'role' => [
                'required',
                Rule::in(array_map(
                    fn (MembershipRole $role): string => $role->value,
                    MembershipRole::assignable(),
                )),
            ],
            ...KeyExpiry::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return KeyExpiry::messages();
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function role(): MembershipRole
    {
        return MembershipRole::from((string) $this->string('role'));
    }

    /** When the key stops working, or null for a key that does not expire. */
    public function expiresAt(): ?CarbonImmutable
    {
        return KeyExpiry::expiresAt($this);
    }
}
