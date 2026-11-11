<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/** The link for administrators on the environment's sign-in page, shown or hidden. */
final class SaveAdminSignInLinkRequest extends FormRequest
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
