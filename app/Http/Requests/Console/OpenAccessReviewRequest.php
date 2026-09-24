<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new access review.
 *
 * The organization is not a field. What the review covers comes from the console
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
            'covers' => ['sometimes', 'string', 'in:organization,staff'],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    /**
     * Whether this reviews STAFF ROLES — every environment-wide grant — rather than the
     * acting organization's access. The environment console's choice alone; the
     * controller refuses it anywhere else.
     */
    public function coversStaff(): bool
    {
        return $this->string('covers')->toString() === 'staff';
    }
}
