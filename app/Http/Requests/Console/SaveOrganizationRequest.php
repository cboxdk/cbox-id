<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A tenant's details.
 *
 * The handle is REQUIRED here, unlike on create: an existing organization already has one,
 * and clearing the field would silently regenerate a URL that other systems may be using.
 * Uniqueness is checked by the controller, which is the only place that knows which
 * organization is allowed to already hold it.
 */
final class SaveOrganizationRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['required', 'string', 'max:190', 'alpha_dash'],
            'metadata' => ['array'],
            'metadata.*.key' => ['nullable', 'string', 'max:120'],
            'metadata.*.value' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['slug' => 'URL handle'];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function slug(): string
    {
        return trim((string) $this->string('slug'));
    }

    /**
     * @return array<string, string>
     */
    public function metadata(): array
    {
        return OrganizationMetadata::from((array) $this->input('metadata', []));
    }
}
