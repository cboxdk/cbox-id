<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Cbox\Id\Identity\Enums\PasswordRevocationScope;
use Cbox\Id\Identity\Rules\PasswordMeetsPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * An administrator replacing somebody else's password.
 *
 * THE MOST COMPLETE TAKEOVER THIS CONSOLE OFFERS, and every consequence of it is an
 * explicit choice on the form rather than a hidden default: whether the credential is a
 * hand-off or a standing one, how it reaches the person, how long it lasts, and how much
 * of their existing access it cuts. A reason is required because the audit entry without
 * one answers "who" and never "why".
 *
 * The password is held to the ENVIRONMENT'S OWN POLICY, tightened by any organization the
 * user belongs to — an administrator is bound by the rules they set for everybody else.
 */
final class SetUserPasswordRequest extends FormRequest
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
            'password' => ['required', 'string', 'max:200', PasswordMeetsPolicy::for($this->userId())],
            'reason' => ['required', 'string', 'max:200'],
            'mode' => ['required', 'in:temporary,permanent'],
            'delivery' => ['required', 'in:reveal,email'],
            'revoke' => ['required', Rule::enum(PasswordRevocationScope::class)],
            'expiryHours' => ['required', 'integer', 'min:0', 'max:8760'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why — it is recorded on the audit trail alongside your name.',
        ];
    }

    /**
     * WHOSE POLICY APPLIES. Read from the route rather than the body: the policy rule is
     * the user's own, and a body field naming a different user would validate the password
     * against somebody else's rules.
     */
    public function userId(): string
    {
        return (string) $this->route('user');
    }

    public function password(): string
    {
        return (string) $this->string('password');
    }

    public function reason(): string
    {
        return trim((string) $this->string('reason'));
    }

    /** True → they MUST choose a new one at next sign-in; the issued one is a hand-off. */
    public function temporary(): bool
    {
        return $this->string('mode')->toString() === 'temporary';
    }

    /** True → shown once in the console; false → emailed to the user. */
    public function reveal(): bool
    {
        return $this->string('delivery')->toString() === 'reveal';
    }

    /** Null when the password stands until it is changed. */
    public function expiresAt(): ?Carbon
    {
        $hours = $this->integer('expiryHours');

        return $this->temporary() && $hours > 0 ? now()->addHours($hours) : null;
    }

    public function revoke(): PasswordRevocationScope
    {
        return PasswordRevocationScope::from((string) $this->string('revoke'));
    }
}
