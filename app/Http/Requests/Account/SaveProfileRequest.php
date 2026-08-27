<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The name a person is addressed by across every screen — and the name stamped on their
 * passkeys.
 *
 * VALIDATED ON THE TRIMMED VALUE, because that is what gets stored. Validating the raw one
 * let "   " through `required|min:1` and then wrote an empty name, blanking the avatar
 * initial and the passkey label.
 *
 * The EMAIL is deliberately not here. It is the sign-in identifier, and letting it change
 * without re-proving control of the new address is how account takeover works — so it
 * moves through a verification flow, not a text field on this page.
 */
final class SaveProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['displayName' => trim((string) $this->string('displayName'))]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'displayName' => ['required', 'string', 'min:1', 'max:120'],
        ];
    }

    public function displayName(): string
    {
        return (string) $this->string('displayName');
    }
}
