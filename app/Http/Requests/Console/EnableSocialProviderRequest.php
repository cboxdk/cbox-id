<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Enabling one social sign-in provider.
 *
 * DELIBERATELY THIN. What a given provider needs is catalogue data — Apple wants a team
 * id, a key id and a private key and issues no client secret at all — so the shape cannot
 * be a fixed rule set here without duplicating the catalogue. The controller validates
 * against the template it resolves; this carries the values across and nothing more.
 */
final class EnableSocialProviderRequest extends FormRequest
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
            'provider' => ['required', 'string', 'max:100'],
            'clientId' => ['required', 'string', 'max:400'],
            // Required only from a provider that issues one — see the controller. Demanding
            // it unconditionally made Apple, the one provider whose form was shaped
            // specially, the one provider nobody could finish enabling.
            'clientSecret' => ['nullable', 'string', 'max:5000'],
            'parameters' => ['array'],
            'parameters.*' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function provider(): string
    {
        return (string) $this->string('provider');
    }

    public function clientId(): string
    {
        return trim((string) $this->string('clientId'));
    }

    public function clientSecret(): string
    {
        return trim((string) $this->string('clientSecret'));
    }

    /**
     * @return array<string, string>
     */
    public function parameters(): array
    {
        $out = [];

        foreach ((array) $this->input('parameters', []) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = trim($value);
            }
        }

        return $out;
    }
}
