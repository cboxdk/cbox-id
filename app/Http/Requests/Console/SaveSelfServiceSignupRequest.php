<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/** The environment's self-service sign-up switch, on or off. */
final class SaveSelfServiceSignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }
}
