<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Cbox\Id\Organization\Enums\EnvironmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A new environment inside one of the account's projects — a live IdP, or a sandbox. */
final class StoreEnvironmentRequest extends FormRequest
{
    /**
     * WHICH project is not decided here. The id arrives in the URL and the controller
     * re-resolves it against the acting organization, so a posted id that belongs to
     * somebody else's account resolves to nothing rather than to a permitted write.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>|array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(EnvironmentType::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'environment name'];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function type(): EnvironmentType
    {
        return EnvironmentType::from((string) $this->string('type'));
    }
}
