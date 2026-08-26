<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Platform\Console\VaultScope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A downstream credential to seal and store.
 *
 * WHOSE it is never comes from the payload — {@see VaultScope}
 * derives the owner from the console's own scope, which is the fix for an earlier version
 * that took the owner from the row being written and handed the framework's ownership
 * check its own answer.
 */
final class StoreVaultSecretRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:190'],
            'provider' => ['required', 'string', 'max:190'],
            /*
             * NO max ON THE VALUE. A downstream credential is whatever the provider issues
             * — a PEM private key runs to thousands of characters — and a length rule here
             * would refuse the exact secrets most worth sealing.
             */
            'secret' => ['required', 'string'],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function provider(): string
    {
        return trim((string) $this->string('provider'));
    }

    /** NOT trimmed: leading or trailing whitespace can be part of a credential. */
    public function secret(): string
    {
        return (string) $this->string('secret');
    }
}
