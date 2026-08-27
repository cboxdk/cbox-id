<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/** One product's name, as it appears on the launchpad and on its own bill. */
final class RenameProjectRequest extends FormRequest
{
    /**
     * WHICH project is not decided here — the id is in the URL and the controller
     * resolves it against the acting organization before anything is written.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120']];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }
}
