<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Platform\Enums\SecretGrace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A rotation, and how long the replaced secret keeps working.
 *
 * The grace is one of the periods the page offers, and only those this install allows
 * ({@see SecretGrace::offered()}): a crafted number is refused here in the page's own
 * words rather than by the registry in its. Absent, it is "immediately" — what a rotation
 * always did before an overlap could be chosen, so a caller that never learned about the
 * field gets the behaviour it was written against.
 */
final class RotateClientSecretRequest extends FormRequest
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
            'grace' => ['nullable', 'integer', Rule::in(array_map(
                static fn (SecretGrace $grace): int => $grace->value,
                SecretGrace::offered(),
            ))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'grace.in' => 'Choose how long the current secret keeps working from the periods offered.',
        ];
    }

    public function grace(): SecretGrace
    {
        return SecretGrace::from($this->integer('grace'));
    }
}
