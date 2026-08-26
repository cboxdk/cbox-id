<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating an end-user identity from the environment console.
 *
 * NO PASSWORD IS SET HERE. An administrator inventing a credential for somebody else and
 * passing it over a chat message is the weakest way to open an account; the person gets a
 * one-time sign-in link instead and chooses how they sign in. Setting a password directly
 * IS possible — on the user's own detail page, gated on a step-up and recorded with a
 * reason — because that is recovery, which is a different act from onboarding.
 */
final class CreateEnvironmentUserRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:190'],
            'name' => ['nullable', 'string', 'max:190'],
            'sendLink' => ['boolean'],
        ];
    }

    public function email(): string
    {
        return trim((string) $this->string('email'));
    }

    /** Null rather than an empty string: the column means "we were not told". */
    public function name(): ?string
    {
        $name = trim((string) $this->string('name'));

        return $name === '' ? null : $name;
    }

    /**
     * On by default — a user who cannot sign in is not a user, and the one shape where
     * this is legitimately off (creating the account ahead of time) is a deliberate act.
     */
    public function sendLink(): bool
    {
        return $this->boolean('sendLink');
    }
}
