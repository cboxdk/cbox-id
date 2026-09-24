<?php

declare(strict_types=1);

namespace App\Http\Requests\OAuth;

use Illuminate\Foundation\Http\FormRequest;

/** The name typed on the hosted "create an organization" step. */
final class CreateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // The same bound the signup form puts on the organization it creates.
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.required' => 'Give your organization a name.'];
    }

    public function organizationName(): string
    {
        return trim((string) $this->string('name'));
    }
}
