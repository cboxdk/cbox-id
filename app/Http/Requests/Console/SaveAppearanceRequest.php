<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A hosted-sign-in theme, submitted by the editor.
 *
 * The SHAPE is validated here and the VALUES are not, because `Appearance::fromArray()`
 * already sanitizes every one of them on the way in — a colour that is not a six-digit hex
 * becomes the preset's, an unknown radius becomes the default. Re-stating those rules here
 * would be a second, drifting copy of the sanitizer that decides what actually reaches a
 * public `<style>` block.
 *
 * What is NOT sanitized there is the logo, because it is not part of the typed appearance:
 * it is a URL this server will render into an `<img src>` on an unauthenticated page. It
 * is normalized below, and http and javascript: URLs come out as null rather than as an
 * error, so an old value cannot be resurrected by a validation failure.
 */
final class SaveAppearanceRequest extends FormRequest
{
    /**
     * WHOSE theme this is gets decided in the controller, from the scope and the plane —
     * not from anything submitted.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'theme' => ['required', 'array'],
            'theme.light' => ['required', 'array'],
            'theme.dark' => ['required', 'array'],
            'logo' => ['nullable', 'string', 'max:2048'],
            'environmentDefault' => ['boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function theme(): array
    {
        /** @var array<string, mixed> */
        return (array) $this->input('theme', []);
    }

    /** HTTPS or nothing. */
    public function logo(): ?string
    {
        $logo = trim((string) $this->string('logo'));

        return $logo !== ''
            && filter_var($logo, FILTER_VALIDATE_URL) !== false
            && str_starts_with($logo, 'https://')
                ? $logo
                : null;
    }

    public function environmentDefault(): bool
    {
        return $this->boolean('environmentDefault');
    }
}
