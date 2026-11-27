<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new identity platform under this organization: its name and, where the deployment
 * bills, the plan it should run on. The plan is checked against the offered list by the
 * controller, which has the organization.
 */
final class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'plan' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function plan(): ?string
    {
        $plan = trim((string) $this->string('plan'));

        return $plan === '' ? null : $plan;
    }
}
