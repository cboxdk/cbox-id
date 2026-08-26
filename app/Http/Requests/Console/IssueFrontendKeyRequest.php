<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Cbox\Id\FrontendApi\Enums\KeyMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new publishable key, and the origins it may be presented from.
 *
 * NOTHING IS NORMALISED OR VALIDATED ABOUT AN ORIGIN HERE. That belongs to the store,
 * which is the only place that can refuse consistently whether the input arrived from this
 * form, an API call, or a seeder — and whose refusal names the offending line. A regex
 * here would be a second, weaker opinion that disagrees on the cases that matter.
 */
final class IssueFrontendKeyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'mode' => ['required', Rule::enum(KeyMode::class)],
            // Newline-separated, because that is how a person pastes a list of URLs.
            'origins' => ['required', 'string', 'max:2000'],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function mode(): KeyMode
    {
        return KeyMode::from((string) $this->string('mode'));
    }

    /**
     * One origin per line, blanks dropped.
     *
     * @return list<string>
     */
    public function origins(): array
    {
        return FrontendKeyOrigins::parse((string) $this->string('origins'));
    }
}
