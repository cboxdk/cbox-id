<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An address on its own — the identifier-first step, and the magic-link request.
 *
 * One request for both because they ask for exactly the same thing, and the difference
 * between them is what the server does with it rather than what the person typed.
 */
final class SendMagicLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }

    public function email(): string
    {
        return (string) $this->string('email');
    }
}
