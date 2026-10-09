<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** A code texted to the person's phone. */
final class VerifySmsCodeRequest extends FormRequest
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
     * Six to ten digits: the OTP module's code length is configurable within that range
     * (`CBOX_ID_OTP_CODE_LENGTH`), and a texted code is one of its codes.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'smsCode' => ['required', 'regex:/^\d{6,10}$/'],
        ];
    }

    public function code(): string
    {
        return (string) $this->string('smsCode');
    }
}
