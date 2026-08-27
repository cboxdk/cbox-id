<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Cbox\Id\ExternalActions\Enums\HookPoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new inline hook: which operation your endpoint gets a say in, and where to call it.
 *
 * THE HOOK POINT IS THE ONE FIELD THAT DECIDES ANYTHING, and it is validated against the
 * enum rather than trusted — without the rule `HookPoint::from()` throws a `ValueError` on
 * a crafted value and the console answers 500 instead of refusing the input. The
 * organization console offered exactly one of the six; both planes offer the enum now,
 * because it is the public contract.
 *
 * WHOSE endpoint it is never comes from the payload. The console chrome owns the acting
 * organization; `environmentWide` asks for something else entirely — an endpoint the
 * environment owns, firing on every tenant — and the controller refuses it off the
 * environment plane rather than trusting an absent checkbox.
 */
final class StoreHookRequest extends FormRequest
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
            'point' => ['required', Rule::enum(HookPoint::class)],
            // `url` and not merely `string`: this platform calls the address itself,
            // synchronously, in the middle of a sign-in.
            'url' => ['required', 'url', 'max:500'],
            'environmentWide' => ['boolean'],
        ];
    }

    public function point(): HookPoint
    {
        return HookPoint::from((string) $this->string('point'));
    }

    public function url(): string
    {
        return trim((string) $this->string('url'));
    }

    public function environmentWide(): bool
    {
        return $this->boolean('environmentWide');
    }
}
