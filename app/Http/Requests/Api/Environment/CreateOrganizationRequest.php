<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Environment;

use Cbox\Id\Organization\Enums\OrganizationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /v1/organizations` — a tenant, optionally with its owner and its parent.
 *
 * The slug is optional: left out it is derived from the name and walked to the first free
 * one. A caller that retries a create should SEND one, because a derived slug makes a
 * retried request a second organization (`acme-2`) where a sent one makes it a 422
 * `slug_taken` the caller can recognise.
 */
final class CreateOrganizationRequest extends FormRequest
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
            'slug' => ['sometimes', 'nullable', 'string', 'max:190', 'alpha_dash'],
            'type' => ['sometimes', Rule::enum(OrganizationType::class)],
            'parent_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'owner_user_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function name(): string
    {
        return trim($this->string('name')->toString());
    }

    public function slug(): ?string
    {
        return $this->filled('slug') ? $this->string('slug')->toString() : null;
    }

    public function type(): OrganizationType
    {
        return $this->enum('type', OrganizationType::class) ?? OrganizationType::Customer;
    }

    public function parentId(): ?string
    {
        return $this->filled('parent_id') ? $this->string('parent_id')->toString() : null;
    }

    public function ownerUserId(): ?string
    {
        return $this->filled('owner_user_id') ? $this->string('owner_user_id')->toString() : null;
    }
}
