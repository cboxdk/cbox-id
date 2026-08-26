<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new access review.
 *
 * ONE FIELD, and the organization is not it. What the review covers comes from the console
 * chrome's acting organization; a picker on this form was the second place that answer
 * lived, and the two planes validated it differently.
 */
final class OpenAccessReviewRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:190'],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }
}
