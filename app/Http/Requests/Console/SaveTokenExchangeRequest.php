<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Whether this app may exchange one token for another (RFC 8693 token exchange).
 */
final class SaveTokenExchangeRequest extends FormRequest
{
    /** WHICH app is in the URL, and the controller re-resolves it against the scope. */
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
            'enabled' => ['required', 'boolean'],
        ];
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }
}
