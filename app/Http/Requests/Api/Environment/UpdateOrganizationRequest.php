<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Environment;

use Cbox\Id\Organization\ValueObjects\OrganizationChanges;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /v1/organizations/{id}` — rename, or change the slug. A field left out is left
 * alone; a request that changes nothing answers with the organization as it is and records
 * nothing.
 */
final class UpdateOrganizationRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'min:1', 'max:190'],
            'slug' => ['sometimes', 'string', 'min:1', 'max:190', 'alpha_dash'],
        ];
    }

    public function changes(): OrganizationChanges
    {
        return new OrganizationChanges(
            name: $this->has('name') ? trim($this->string('name')->toString()) : null,
            slug: $this->has('slug') ? $this->string('slug')->toString() : null,
        );
    }
}
