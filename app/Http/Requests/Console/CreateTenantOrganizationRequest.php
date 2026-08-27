<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Cbox\Id\Organization\Enums\OrganizationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A tenant in the plane the operator console is currently pointed at.
 *
 * The TYPE is validated against the enum rather than a hand-written list, so a new member
 * of `OrganizationType` cannot be silently unofferable — and an unknown one cannot reach
 * `OrganizationType::from()`, which would be a 500 on a form field.
 */
final class CreateTenantOrganizationRequest extends FormRequest
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
            'type' => ['required', Rule::enum(OrganizationType::class)],
            'parentId' => ['nullable', 'string'],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function type(): OrganizationType
    {
        return OrganizationType::from((string) $this->string('type'));
    }

    public function parentId(): ?string
    {
        $parent = trim((string) $this->string('parentId'));

        return $parent === '' ? null : $parent;
    }
}
