<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Http\Requests\Console\KeyExpiry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A person creating an API key for an app, for themself.
 *
 * Shape only. Whether the app offers keys to that organization, whether the person is a
 * member there, and whether they hold every permission they ticked are answered by the
 * services behind the controller — against the signed-in subject, never against anything
 * in this body.
 */
final class IssueAppApiKeyRequest extends FormRequest
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
            'client_id' => ['required', 'string', 'max:255'],
            'organization_id' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            // Empty is allowed: a key that identifies its holder to the app and may do
            // nothing else is a real thing to want (a read of "who is calling").
            'permissions' => ['nullable', 'array', 'max:200'],
            'permissions.*' => ['string', 'min:1', 'max:255', 'distinct'],
            ...KeyExpiry::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...KeyExpiry::messages(),
            'client_id.required' => 'Choose the app this key is for.',
            'name.required' => 'Give the key a name, so you can tell it apart later.',
        ];
    }

    public function clientId(): string
    {
        return trim((string) $this->string('client_id'));
    }

    public function organizationId(): string
    {
        return trim((string) $this->string('organization_id'));
    }

    public function keyName(): string
    {
        return trim((string) $this->string('name'));
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        $permissions = $this->input('permissions');

        return is_array($permissions)
            ? array_values(array_filter($permissions, 'is_string'))
            : [];
    }

    /** When the key stops working, or null for a key that does not expire. */
    public function expiresAt(): ?CarbonImmutable
    {
        return KeyExpiry::expiresAt($this);
    }
}
