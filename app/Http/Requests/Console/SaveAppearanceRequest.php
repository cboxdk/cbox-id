<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Platform\Appearance\BrandImageUpload;
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
 * The IMAGES are not checked here either: they arrive as base64 `data:` URIs — the editor
 * already reads a chosen file that way to preview it — and the action checks the bytes
 * themselves ({@see BrandImageUpload}), for the console and the
 * API alike. Only their presence matters at this layer: a key that is present is a change
 * (null removes the image), a key that is absent leaves it alone.
 */
final class SaveAppearanceRequest extends FormRequest
{
    /**
     * NOT FLASHED BACK on a refusal: an image is up to a megabyte of base64, and flashing
     * it would write that into the session row of every refused save. The editor still
     * holds the file it read; nothing is lost by not echoing it.
     *
     * @var list<string>
     */
    protected $dontFlash = ['images', 'images.logo', 'images.favicon'];

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
            'images' => ['sometimes', 'array:logo,favicon'],
            'images.logo' => ['sometimes', 'nullable', 'string', 'max:1400100'],
            'images.favicon' => ['sometimes', 'nullable', 'string', 'max:349700'],
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

    /**
     * The images the editor changed, keyed as the action takes them: a data URI to store,
     * null to remove. An image the editor did not touch is absent.
     *
     * @return array<string, string|null>
     */
    public function images(): array
    {
        $changed = [];

        foreach (['logo', 'favicon'] as $kind) {
            if ($this->has('images.'.$kind)) {
                $value = $this->input('images.'.$kind);
                $changed[$kind] = is_string($value) && $value !== '' ? $value : null;
            }
        }

        return $changed;
    }

    public function environmentDefault(): bool
    {
        return $this->boolean('environmentDefault');
    }
}
