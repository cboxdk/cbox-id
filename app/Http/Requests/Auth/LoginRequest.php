<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A password sign-in attempt.
 *
 * NO POLICY RULE on the password, deliberately: this is somebody proving they know the
 * one they already have. Refusing it here for being too short would lock out every
 * account created before a policy tightened, and would say so on the sign-in page —
 * which is an inventory of which accounts have weak passwords.
 */
final class LoginRequest extends FormRequest
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
            'password' => ['required', 'string'],
        ];
    }

    public function email(): string
    {
        return (string) $this->string('email');
    }

    public function password(): string
    {
        return (string) $this->string('password');
    }
}
