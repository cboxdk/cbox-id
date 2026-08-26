<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The branding one altitude carries: its palette, its name, its sender and its two images.
 *
 * THE UPLOADS ARE THE PART THAT MATTERS. `accept="image/*"` on an input is a hint to the
 * file picker and nothing more. The asset store keeps the content-guessed extension and
 * writes to the public disk, so an SVG containing a script tag would then be served from the
 * application's OWN origin — a static path, so neither the CSP nor `nosniff` applies to it,
 * and every admin's session cookie lives on that origin. SVG is excluded deliberately: it is
 * the only raster-adjacent format that is also a script host.
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
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'favicon' => ['nullable', 'image', 'mimes:png,ico,webp', 'max:256'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.mimes' => 'Use a PNG, JPEG or WebP. SVG is not accepted — it can carry a script.',
            'favicon.mimes' => 'Use a PNG, ICO or WebP. SVG is not accepted — it can carry a script.',
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
