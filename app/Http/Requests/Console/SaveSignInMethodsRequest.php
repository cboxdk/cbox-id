<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Actions\SignIn\UpdateSignInPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The environment's sign-in methods and session lengths, as the Authentication policy page
 * posts them. The deployment's ceiling is the action's to enforce ({@see UpdateSignInPolicy}),
 * which names it in the refusal; this only reads the form.
 */
final class SaveSignInMethodsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'passkeys' => ['required', 'boolean'],
            'magicLink' => ['required', 'boolean'],
            'botChallenge' => ['required', 'boolean'],
            // Empty means "the deployment's" — not zero, and not a validation failure.
            'sessionIdleMinutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'sessionAbsoluteMinutes' => ['nullable', 'integer', 'min:5', 'max:525600'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'sessionIdleMinutes' => 'idle timeout',
            'sessionAbsoluteMinutes' => 'session lifetime',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['sessionIdleMinutes', 'sessionAbsoluteMinutes'] as $optional) {
            if ($this->input($optional) === '') {
                $this->merge([$optional => null]);
            }
        }
    }

    /**
     * The action's input.
     *
     * @return array{passkeys: bool, magic_link: bool, bot_challenge: bool, session_idle_minutes: int|null, session_absolute_minutes: int|null}
     */
    public function methodsInput(): array
    {
        return [
            'passkeys' => $this->boolean('passkeys'),
            'magic_link' => $this->boolean('magicLink'),
            'bot_challenge' => $this->boolean('botChallenge'),
            'session_idle_minutes' => $this->input('sessionIdleMinutes') === null ? null : $this->integer('sessionIdleMinutes'),
            'session_absolute_minutes' => $this->input('sessionAbsoluteMinutes') === null ? null : $this->integer('sessionAbsoluteMinutes'),
        ];
    }
}
