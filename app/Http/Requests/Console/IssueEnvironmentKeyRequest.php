<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use App\Platform\EnvironmentKeyScopes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new environment management-plane key.
 *
 * THE SCOPES ARE THE CREDENTIAL. A key with none is not a weaker key, it is a key that can
 * do nothing — so at least one is required, and every one is checked against the enum
 * rather than trusted. The environment is checked for REACHABILITY by the controller,
 * because whether a given id is one the caller may mint against is an authorization
 * question and not a shape question.
 */
final class IssueEnvironmentKeyRequest extends FormRequest
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
            'environment' => ['required', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            // The OFFERED scopes, not every case of the enum: a scope no route requires
            // is one the form does not show, and a key carrying it would be a promise
            // the API cannot keep.
            'scopes.*' => [Rule::in(EnvironmentKeyScopes::offeredValues())],
            ...KeyExpiry::rules(),
            // What it is for, and which of its actions wait for its owner's approval — the
            // agent create flow asks both; the workspace console's form asks neither.
            'description' => ['nullable', 'string', 'max:500'],
            'approval' => ['nullable', Rule::in(['none', 'write', 'destructive', 'critical'])],
            'approvalActions' => ['nullable', 'array', 'max:100'],
            'approvalActions.*' => ['string', Rule::in(array_map(
                static fn (ActionDefinition $action): string => $action->name,
                app(ActionRegistry::class)->forPlane(ActionPlane::Environment),
            ))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scopes.required' => 'Choose at least one scope — a key with none can do nothing.',
            'scopes.min' => 'Choose at least one scope — a key with none can do nothing.',
            ...KeyExpiry::messages(),
        ];
    }

    public function environmentId(): string
    {
        return trim((string) $this->string('environment'));
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return array_values(array_unique(array_filter(
            (array) $this->input('scopes', []),
            'is_string',
        )));
    }

    /** When the key stops working, or null for a key that does not expire. */
    public function description(): ?string
    {
        $description = trim((string) $this->string('description'));

        return $description === '' ? null : $description;
    }

    /**
     * The key's approval policy in the shape `keys.create` takes (`require_approval`), or
     * null for none — which is also what an empty level with no named actions means.
     *
     * @return array{min_danger: string|null, actions: list<string>}|null
     */
    public function requireApproval(): ?array
    {
        $level = (string) $this->string('approval');
        $actions = array_values(array_unique(array_filter((array) $this->input('approvalActions', []), 'is_string')));
        $minDanger = in_array($level, ['write', 'destructive', 'critical'], true) ? $level : null;

        return $minDanger === null && $actions === [] ? null : ['min_danger' => $minDanger, 'actions' => $actions];
    }

    public function expiresAt(): ?CarbonImmutable
    {
        return KeyExpiry::expiresAt($this);
    }
}
