<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Cbox\Id\OAuthServer\Support\AccessTokenLifetime;
use Illuminate\Foundation\Http\FormRequest;

/**
 * How long this app's access tokens live, in whole minutes — or empty for the install's
 * default.
 *
 * Minutes, because that is how people think about a session; the registry stores seconds.
 * Bounded by the same two numbers the registry refuses outside of — a minute, below which
 * clock skew expires a token before it is used, and the install's ceiling — so the form
 * says so in its own words before the registry would in its.
 */
final class SaveTokenLifetimeRequest extends FormRequest
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
            'minutes' => [
                'nullable',
                'integer',
                'min:'.intdiv(AccessTokenLifetime::MIN_SECONDS, 60),
                'max:'.self::ceilingMinutes(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $ceiling = self::ceilingMinutes();

        return [
            'minutes.integer' => 'Enter a whole number of minutes, or leave it empty for the default.',
            'minutes.min' => 'An access token has to live at least a minute.',
            'minutes.max' => "This install lets an app's access tokens live at most {$ceiling} minutes.",
        ];
    }

    /** The lifetime to store, in seconds; null returns the app to the install's default. */
    public function seconds(): ?int
    {
        $minutes = $this->input('minutes');

        return $minutes === null || $minutes === '' ? null : $this->integer('minutes') * 60;
    }

    /** The install's ceiling, in whole minutes — never more than it allows. */
    public static function ceilingMinutes(): int
    {
        return intdiv(AccessTokenLifetime::max(), 60);
    }
}
