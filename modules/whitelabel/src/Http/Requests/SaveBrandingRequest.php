<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The branding one altitude carries: its palette, its name, its sender and its welcome mail.
 *
 * The logo and favicon are not here any more: they are uploaded on the Appearance page,
 * through `branding.appearance.set`, which checks the bytes themselves (SVG refused — it is
 * a script host — and the format sniffed, never trusted from a name).
 */
final class SaveBrandingRequest extends FormRequest
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
            'palette' => ['array'],
            'palette.*' => ['nullable', 'string', 'max:100'],
            'appName' => ['nullable', 'string', 'max:120'],
            'emailFromName' => ['nullable', 'string', 'max:120'],
            'emailTemplate' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function palette(): array
    {
        $out = [];

        foreach ((array) $this->input('palette', []) as $token => $value) {
            if (is_string($token) && is_string($value)) {
                $out[$token] = trim($value);
            }
        }

        return $out;
    }

    /** Null rather than an empty string: the column means "not set", not "set to nothing". */
    public function appName(): ?string
    {
        $value = trim((string) $this->string('appName'));

        return $value === '' ? null : $value;
    }

    public function emailFromName(): ?string
    {
        $value = trim((string) $this->string('emailFromName'));

        return $value === '' ? null : $value;
    }

    public function emailTemplate(): string
    {
        return (string) $this->string('emailTemplate');
    }
}
