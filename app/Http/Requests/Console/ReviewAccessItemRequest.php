<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One reviewer's decision about one grant.
 *
 * STATED, not toggled. A reviewer moves between the two answers — somebody who mis-clicked
 * Revoke has to be able to say Certify — and a toggle would make the outcome depend on
 * what the page believed the current decision was rather than on what the person just
 * pressed.
 */
final class ReviewAccessItemRequest extends FormRequest
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
            'decision' => ['required', 'in:certified,revoked'],
        ];
    }

    /** Whether the access was confirmed to still be needed. */
    public function certifies(): bool
    {
        return $this->string('decision')->toString() === 'certified';
    }
}
