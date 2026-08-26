<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new role, plus the opening set of permissions ticked on the form.
 *
 * WHOSE role it is never comes from the payload — the console chrome owns the acting
 * organization, and the controller reads it from the scope. Two things here do arrive from
 * the browser and are therefore claims until resolved: `app` (which app's tokens the role
 * is stamped into) is intersected with the apps in reach, and every permission id is
 * re-resolved against the catalogue this administrator may assign from. A checkbox is not
 * a gate.
 */
final class StoreRoleRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:500'],
            // Empty string = every app. A `nullable` field a `<select>` posts as '' would
            // otherwise fail `string` on the empty option.
            'app' => ['nullable', 'string', 'max:190'],
            'environmentWide' => ['boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
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

    /** The app whose tokens this role is stamped into, or null for every app. */
    public function appId(): ?string
    {
        $app = trim((string) $this->string('app'));

        return $app !== '' ? $app : null;
    }

    /**
     * Define it for every organization in the environment rather than for the one being
     * administered. Offered — and accepted — only on the environment plane; the controller
     * refuses it elsewhere rather than trusting the absent checkbox.
     */
    public function environmentWide(): bool
    {
        return $this->boolean('environmentWide');
    }

    /**
     * The permission ids ticked on the form. A list of claims: the controller resolves
     * them against the assignable catalogue and drops what matches nothing.
     *
     * @return list<string>
     */
    public function permissionIds(): array
    {
        return array_values(array_unique(array_filter(
            (array) $this->input('permissions', []),
            'is_string',
        )));
    }
}
