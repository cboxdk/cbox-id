<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Cbox\Id\Identity\Rules\PasswordMeetsPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A self-service signup.
 *
 * NIST SP 800-63B favours length over composition, so the tenant's policy sets a length
 * floor and a known-breach screen and demands no composition. There is no subject yet, so
 * there is no reuse history to check against.
 */
final class RegisterRequest extends FormRequest
{
    /**
     * WHETHER SIGNUP IS OPEN AT ALL is checked in the controller, twice: once when the
     * page is served and again before anything is written, so a form reached or replayed
     * out of band creates nothing.
     */
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
            'organization' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200', PasswordMeetsPolicy::for()],

            /*
             * THE BOT SIGNALS, and neither is validated into a refusal here.
             *
             * `website` is a honeypot: a human never fills it, so a value in it is
             * evidence rather than an error — refusing it outright would tell a bot
             * exactly which field to leave alone next time. `renderedAt` catches a
             * submission that arrives implausibly fast. Both are fed to the risk scorer,
             * which decides what they are worth.
             */
            'website' => ['nullable', 'string', 'max:200'],
            'renderedAt' => ['nullable', 'integer'],
            'turnstileToken' => ['nullable', 'string', 'max:4096'],
        ];
    }

    public function organization(): string
    {
        return trim((string) $this->string('organization'));
    }

    public function name(): ?string
    {
        return trim((string) $this->string('name')) ?: null;
    }

    public function email(): string
    {
        return (string) $this->string('email');
    }

    public function password(): string
    {
        return (string) $this->string('password');
    }

    public function honeypot(): string
    {
        return (string) $this->string('website');
    }

    public function renderedAt(): int
    {
        return $this->integer('renderedAt');
    }

    public function turnstileToken(): string
    {
        return (string) $this->string('turnstileToken');
    }
}
