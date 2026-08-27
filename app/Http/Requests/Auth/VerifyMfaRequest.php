<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** An authenticator code. */
final class VerifyMfaRequest extends FormRequest
{
    /**
     * The pending sign-in IS the authorization, and it lives in the session where the
     * controller reads it. There is nothing here to authorize against.
     */
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
            'code' => ['required', 'digits:6'],
        ];
    }

    public function code(): string
    {
        return (string) $this->string('code');
    }
}
