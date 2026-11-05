<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Platform\Enums\KeyLifetime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The one place a key form's lifetime choice becomes an expiry.
 *
 * Shared by the account key form and the environment key form because they ask the same
 * question in the same shape, and two copies of it is how one comes to accept a date in
 * the past that the other refuses.
 *
 * `expires` is OPTIONAL and means "never" when absent: an integration that posts to these
 * forms without the field — including every test and script written before the field
 * existed — keeps minting the key it always did.
 */
final class KeyExpiry
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'expires' => ['nullable', Rule::enum(KeyLifetime::class)],
            // Tomorrow at the earliest, and no further out than the longest preset times
            // ten: a custom date is for "the end of the contract", not for a typo that
            // turns 2027 into 20270.
            'expiresOn' => [
                'nullable',
                Rule::requiredIf(fn (): bool => request()->input('expires') === KeyLifetime::Custom->value),
                'date_format:Y-m-d',
                'after:today',
                'before:'.CarbonImmutable::now()->addYears(10)->toDateString(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'expiresOn.required' => __('console.choose_the_date_the_key_should_stop_working_001d29b7'),
            'expiresOn.after' => __('console.choose_a_date_after_today_c0b95f45'),
            'expiresOn.before' => __('console.choose_a_date_within_the_next_ten_years_9cc6cdfb'),
        ];
    }

    /** The lifetime chosen, or {@see KeyLifetime::Never} when the form sent none. */
    public static function lifetime(FormRequest $request): KeyLifetime
    {
        return KeyLifetime::tryFrom((string) $request->string('expires')) ?? KeyLifetime::Never;
    }

    /** When a key minted by this request stops working, or null for never. */
    public static function expiresAt(FormRequest $request): ?CarbonImmutable
    {
        $input = trim((string) $request->string('expiresOn'));
        $on = $input === '' ? false : CarbonImmutable::createFromFormat('Y-m-d', $input, 'UTC');

        return self::lifetime($request)->expiresAt(CarbonImmutable::now(), $on === false ? null : $on);
    }
}
