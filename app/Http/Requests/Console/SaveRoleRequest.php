<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A role's name and description.
 *
 * Not its app scope and not its organization: both decide which tokens the role reaches
 * and which tenants may hold it, and moving a role between them after people hold it is a
 * silent change to who has access. Delete and re-create is the honest path, and it shows
 * up on the audit trail as what it is.
 */
final class SaveRoleRequest extends FormRequest
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
}
