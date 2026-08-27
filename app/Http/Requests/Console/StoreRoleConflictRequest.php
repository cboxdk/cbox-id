<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new segregation-of-duties rule: the roles that must never sit with the same person.
 *
 * TWO IS THE MINIMUM AND IT IS A RULE, not a hint. A "mutually exclusive set" of one role
 * conflicts with nothing — it would sit in the list looking like a control while blocking
 * no grant at all, which is worse than having no rule, because somebody has written it
 * down and believes it is working.
 */
final class StoreRoleConflictRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:500'],
            'roles' => ['required', 'array', 'min:2'],
            'roles.*' => ['string'],
            'environmentWide' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'roles.required' => 'Select at least two roles for a mutually-exclusive set.',
            'roles.min' => 'Select at least two roles for a mutually-exclusive set.',
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function description(): ?string
    {
        $description = trim((string) $this->string('description'));

        return $description !== '' ? $description : null;
    }

    /**
     * The roles that conflict. A list rather than whatever shape the payload had: the
     * contract stores this straight onto the record, and a map with gaps in its keys
     * serialises back as an object rather than an array.
     *
     * @return list<string>
     */
    public function roleIds(): array
    {
        return array_values(array_unique(array_filter(
            (array) $this->input('roles', []),
            'is_string',
        )));
    }

    /**
     * Bind every organization in the environment rather than the one being administered.
     * Offered — and accepted — only on the environment plane; the controller refuses it
     * elsewhere rather than trusting an absent checkbox.
     */
    public function environmentWide(): bool
    {
        return $this->boolean('environmentWide');
    }
}
