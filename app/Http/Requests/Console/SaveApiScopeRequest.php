<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A scope an API owns: its key (only when adding one — a key is what apps already hold, so
 * it is never renamed), what it lets an app do, and whether organizations' apps may request
 * it.
 */
final class SaveApiScopeRequest extends FormRequest
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
            'key' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:255'],
            'tenantRequestable' => ['boolean'],
        ];
    }

    public function key(): string
    {
        return trim((string) $this->string('key'));
    }

    public function description(): ?string
    {
        $description = trim((string) $this->string('description'));

        return $description === '' ? null : $description;
    }

    public function tenantRequestable(): bool
    {
        return $this->boolean('tenantRequestable');
    }
}
