<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Cbox\Id\Organization\ValueObjects\ApiKeyPrefix;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The prefix this app's API keys carry — `acme_live` makes keys shaped
 * `acme_live_…`. Declaring one is what lets the people who use the app create keys for
 * it; empty stops new keys being created.
 *
 * The format is {@see ApiKeyPrefix}'s own test, asked here so the refusal is a sentence a
 * person can act on rather than the pattern it is checked against.
 */
final class SaveApiKeyPrefixRequest extends FormRequest
{
    /** WHICH app is in the URL, and the controller re-resolves it against the scope. */
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
            'prefix' => [
                'nullable',
                'string',
                'max:32',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    if (preg_match(ApiKeyPrefix::PATTERN, $value) !== 1) {
                        $fail(__('console.use_2_to_16_lowercase_letters_or_digits_star_cda1343d'));

                        return;
                    }

                    if (ApiKeyPrefix::tryFrom($value) === null) {
                        $fail(__('console.that_prefix_is_reserved_for_cbox_id_s_own_ke_ae985061'));
                    }
                },
            ],
        ];
    }

    public function prefix(): ?string
    {
        $prefix = trim((string) $this->string('prefix'));

        return $prefix === '' ? null : $prefix;
    }
}
