<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A domain an external IT admin claims for the organization their link was minted for.
 *
 * LOWER-CASED BEFORE VALIDATION, because that is what gets stored and what DNS answers to
 * — a hostname is case-insensitive and the uniqueness check is not.
 */
final class AddPortalDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['domain' => mb_strtolower(trim((string) $this->string('domain')))]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'domain' => [
                'required',
                'string',
                'max:253',
                'regex:/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['domain.regex' => 'Enter a valid domain, e.g. acme.com.'];
    }

    public function domain(): string
    {
        return (string) $this->string('domain');
    }
}
