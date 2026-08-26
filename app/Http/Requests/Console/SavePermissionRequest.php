<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What a manual permission's row can be changed to: its description, and whether tenants
 * may compose it.
 *
 * NOT the key. Roles are composed of these by id, but an app reading `permissions` out of
 * a token matches on the STRING — so renaming one silently changes what every deployed app
 * checks against, with no way for the console to know which. Delete and re-create is the
 * honest path, and it shows on the audit trail as what it is.
 */
final class SavePermissionRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:500'],
            'tenantAssignable' => ['boolean'],
        ];
    }

    public function description(): ?string
    {
        $description = trim((string) $this->string('description'));

        return $description !== '' ? $description : null;
    }

    public function tenantAssignable(): bool
    {
        return $this->boolean('tenantAssignable');
    }
}
