<?php

declare(strict_types=1);

namespace App\Http\Requests\OAuth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The organization chosen on the hosted picker. Only its SHAPE is checked here; whether
 * the person may use it is the controller's question, asked of the membership tables on
 * this request rather than trusted from the list the page was drawn with.
 */
final class ChooseOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'organization' => ['required', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['organization.required' => 'Choose an organization to continue.'];
    }

    public function organizationId(): string
    {
        return (string) $this->string('organization');
    }
}
