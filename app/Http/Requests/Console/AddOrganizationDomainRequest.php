<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An email domain an organization claims.
 *
 * LOWER-CASED ON THE WAY IN. A domain is case-insensitive, and storing `Acme.com` beside
 * `acme.com` would let the same domain be claimed twice — once by each tenant that typed
 * it differently.
 */
final class AddOrganizationDomainRequest extends FormRequest
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
            'domain' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['domain.regex' => 'Enter a domain name — acme.com, not an address or a URL.'];
    }

    public function domain(): string
    {
        return mb_strtolower(trim((string) $this->string('domain')));
    }
}
