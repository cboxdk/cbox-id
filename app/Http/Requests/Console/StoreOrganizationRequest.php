<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new tenant inside this environment.
 *
 * THE HANDLE IS OPTIONAL AND ALWAYS UNIQUE. Left blank it is derived from the name, and
 * either way the controller walks it to the first free one — a slug collision is not
 * something to hand back as a validation error, because the person did not choose the
 * value that collided.
 */
final class StoreOrganizationRequest extends FormRequest
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
            'slug' => ['nullable', 'string', 'max:190', 'alpha_dash'],
            'metadata' => ['array'],
            'metadata.*.key' => ['nullable', 'string', 'max:120'],
            'metadata.*.value' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function slug(): ?string
    {
        $slug = trim((string) $this->string('slug'));

        return $slug !== '' ? $slug : null;
    }

    /**
     * The metadata rows, as a map, with blank keys dropped.
     *
     * A row whose key is empty is a row somebody added and did not fill in — storing it
     * under `''` would put a nameless value in the tenant's settings that no screen can
     * ever address.
     *
     * @return array<string, string>
     */
    public function metadata(): array
    {
        return OrganizationMetadata::from((array) $this->input('metadata', []));
    }
}
