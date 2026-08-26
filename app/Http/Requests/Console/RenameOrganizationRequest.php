<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/** The account's own name, as shown across the console and on its bills. */
final class RenameOrganizationRequest extends FormRequest
{
    /**
     * WHO may rename it is a capability question, asked in the controller where the
     * organization is resolved — so the check and the write cannot disagree about which
     * organization they are talking about.
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
