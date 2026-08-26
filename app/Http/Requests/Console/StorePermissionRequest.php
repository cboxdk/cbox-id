<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A manual permission: a `feature:action` key somebody writes here rather than an app
 * declaring it.
 *
 * WHICH TIER it lands in is not a field. The plane decides — shared with the environment,
 * or the acting organization's alone — because an environment administrator who has
 * narrowed the console to one tenant is still administering the environment.
 */
final class StorePermissionRequest extends FormRequest
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
            // feature:action — e.g. invoices:create, reports:read. Case-insensitive on the
            // way in and lower-cased on the way out, so `Invoices:Create` is refused as a
            // duplicate of `invoices:create` rather than stored beside it.
            'name' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9_.-]*:[a-z0-9][a-z0-9_.*-]*$/i'],
            'description' => ['nullable', 'string', 'max:500'],
            'tenantAssignable' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'A permission key is two words joined by a colon — a feature and an action, like invoices:create.',
        ];
    }

    public function key(): string
    {
        return mb_strtolower(trim((string) $this->string('name')));
    }

    public function description(): ?string
    {
        $description = trim((string) $this->string('description'));

        return $description !== '' ? $description : null;
    }

    /**
     * Whether organizations may compose this into their own roles.
     *
     * Only the shared tier has anything to decide here, and the controller is what decides
     * whether to read it — an organization's own row is always assignable by its owner.
     */
    public function tenantAssignable(): bool
    {
        return $this->boolean('tenantAssignable');
    }
}
