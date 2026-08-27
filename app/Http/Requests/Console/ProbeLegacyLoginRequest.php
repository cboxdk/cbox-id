<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The address to probe the declared legacy endpoint with.
 *
 * THE OPERATOR'S OWN, never somebody else's — the probe asks another system whether an
 * account exists there, which is an answer nobody should be able to obtain about a third
 * party by typing their address into this box. The page says so; this is only the shape.
 */
final class ProbeLegacyLoginRequest extends FormRequest
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
            'email' => ['required', 'email'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Enter an address that exists in your old system.',
            'email.email' => 'Enter an address that exists in your old system.',
        ];
    }

    public function email(): string
    {
        return trim((string) $this->string('email'));
    }
}
