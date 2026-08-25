<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** One of the recovery codes saved when two-factor was enabled. */
final class VerifyRecoveryCodeRequest extends FormRequest
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
            'recoveryCode' => ['required', 'string', 'min:6', 'max:64'],
        ];
    }

    public function recoveryCode(): string
    {
        return (string) $this->string('recoveryCode');
    }
}
