<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The client authorized to lease a stored secret.
 *
 * Deny-by-default is the vault's whole shape: a secret is leasable by nothing until a
 * client is named here, so this field is the access-control decision rather than a
 * setting.
 */
final class GrantVaultAccessRequest extends FormRequest
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
            'client' => ['required', 'string', 'max:190'],
        ];
    }

    public function clientId(): string
    {
        return trim((string) $this->string('client'));
    }
}
