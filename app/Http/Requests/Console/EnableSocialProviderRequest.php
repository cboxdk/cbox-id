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
            // The id the form reserved so it could show the real redirect URI; the action
            // refuses one that is not a free ULID.
            'reservedId' => ['nullable', 'string', 'max:26'],
            // Extra scopes as one line, separated by spaces or commas — the way a provider's
            // own documentation writes them.
            'scopes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function reservedId(): ?string
    {
        $id = trim((string) $this->string('reservedId'));

        return $id === '' ? null : $id;
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', (string) $this->string('scopes')) ?: [], static fn (string $scope): bool => $scope !== ''));
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
