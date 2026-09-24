<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Rules\SecureUriLines;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Copy an app into another environment of the same project: which one, what to call the
 * copy there, and where it may send people back to.
 *
 * The redirect URIs are asked for because they are the one part of an app that almost
 * always differs between environments — staging's callback is not production's — and a
 * copy that silently kept staging's would send production's sign-ins to staging.
 */
final class CopyClientRequest extends FormRequest
{
    /** Which environment is a question the controller answers against what may be reached. */
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
            'environment' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:190'],
            'redirectUris' => ['nullable', 'string', 'max:2000', new SecureUriLines(
                'Each redirect URI must use https (http is allowed only on localhost) — e.g. https://app.example.com/callback.',
            )],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'environment.required' => 'Choose the environment to copy this app into.',
        ];
    }

    public function environment(): string
    {
        return (string) $this->string('environment');
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    /** @return list<string> */
    public function redirectUris(): array
    {
        return SecureUriLines::split((string) $this->string('redirectUris'));
    }
}
