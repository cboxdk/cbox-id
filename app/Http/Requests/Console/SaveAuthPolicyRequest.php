<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The sign-in rules for whichever level the console is editing. */
final class SaveAuthPolicyRequest extends FormRequest
{
    /**
     * WHICH LEVEL — the environment's baseline or one organization's override — is the
     * plane's answer, taken in the controller. Nothing submitted decides it.
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
            'minLength' => ['required', 'integer', 'min:8', 'max:128'],
            'requireBreachCheck' => ['required', 'boolean'],
            // Nullable, and empty means "no limit" — which is the LOOSEST value either can
            // hold, and therefore the one the override check below has to look hardest at.
            'maxAgeDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'reuseHistory' => ['required', 'integer', 'min:0', 'max:24'],
            'lockoutThreshold' => ['nullable', 'integer', 'min:3', 'max:100'],
            // Parsed at the HTTP edge. Without these the `::from()` below throws a
            // ValueError and the console answers a crafted payload with a 500 instead of a
            // field error.
            'mfa' => ['required', Rule::enum(MfaRequirement::class)],
            'sso' => ['required', Rule::enum(SsoEnforcement::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'minLength' => 'minimum length',
            'maxAgeDays' => 'maximum age',
            'reuseHistory' => 'reuse history',
            'lockoutThreshold' => 'lockout threshold',
        ];
    }

    /**
     * The empty string is how a cleared number input arrives, and it means "no limit" —
     * not zero, and not a validation failure.
     *
     * @return array<string, mixed>
     */
    protected function prepareForValidation(): array
    {
        foreach (['maxAgeDays', 'lockoutThreshold'] as $optional) {
            if ($this->input($optional) === '') {
                $this->merge([$optional => null]);
            }
        }

        return [];
    }

    public function policy(): AuthPolicy
    {
        return new AuthPolicy(
            minLength: (int) $this->integer('minLength'),
            requireBreachCheck: $this->boolean('requireBreachCheck'),
            maxAgeDays: $this->input('maxAgeDays') === null ? null : (int) $this->integer('maxAgeDays'),
            reuseHistory: (int) $this->integer('reuseHistory'),
            mfa: MfaRequirement::from((string) $this->string('mfa')),
            sso: SsoEnforcement::from((string) $this->string('sso')),
            lockoutThreshold: $this->input('lockoutThreshold') === null ? null : (int) $this->integer('lockoutThreshold'),
        );
    }
}
