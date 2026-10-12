<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Platform\Appearance\BrandImageUpload;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Branding page's ONE save: the hosted-sign-in theme and its images, and the
 * white-label name, sender, welcome mail and console palette beside them.
 *
 * Either half may be absent — the page sends only what changed — but not both. They were
 * two forms with two Save buttons, and an administrator who uploaded a logo and pressed the
 * Save nearest to them (the name & email one) saw "Branding saved." while the logo was
 * thrown away.
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
            // The theme travels with any image change: the action that stores the images is
            // the theme's own.
            'theme' => ['required_without:profile', 'required_with:images', 'array'],
            'theme.light' => ['required_with:theme', 'array'],
            'theme.dark' => ['required_with:theme', 'array'],
            'images' => ['sometimes', 'array:logo,favicon'],
            'images.logo' => ['sometimes', 'nullable', 'string', 'max:1400100'],
            'images.favicon' => ['sometimes', 'nullable', 'string', 'max:349700'],
            'environmentDefault' => ['boolean'],
            'profile' => ['sometimes', 'array'],
            'profile.palette' => ['sometimes', 'array'],
            'profile.palette.*' => ['nullable', 'string', 'max:100'],
            'profile.appName' => ['nullable', 'string', 'max:120'],
            'profile.emailFromName' => ['nullable', 'string', 'max:120'],
            'profile.emailTemplate' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** Whether the theme half was sent — the page leaves it out when only the name changed. */
    public function savesTheme(): bool
    {
        return $this->has('theme');
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
     * The white-label half, as `branding.whitelabel.set` takes it, or null when it was not
     * sent. Blank names are null — "not set" — and the palette keeps only string tokens.
     *
     * @return array{palette: array<string, string>, app_name: ?string, email_from_name: ?string, email_template: string}|null
     */
    public function profile(): ?array
    {
        if (! $this->has('profile')) {
            return null;
        }

        $palette = [];

        foreach ((array) $this->input('profile.palette', []) as $token => $value) {
            if (is_string($token)) {
                $palette[$token] = is_string($value) ? trim($value) : '';
            }
        }

        $blank = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;
        $template = $this->input('profile.emailTemplate');

        return [
            'palette' => $palette,
            'app_name' => $blank($this->input('profile.appName')),
            'email_from_name' => $blank($this->input('profile.emailFromName')),
            'email_template' => is_string($template) ? $template : '',
        ];
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
