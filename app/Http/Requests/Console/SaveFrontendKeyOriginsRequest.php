<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A replacement allow-list for a publishable key.
 *
 * REQUIRED, not nullable: a key with no origins is presentable from nowhere, which is a
 * revoked key expressed as an empty textarea. Somebody who wants that should revoke it, so
 * the trail says what happened.
 */
final class SaveFrontendKeyOriginsRequest extends FormRequest
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
            'origins' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'origins.required' => 'List at least one origin — a key presentable from nowhere is a revoked key. Revoke it instead.',
        ];
    }

    /**
     * @return list<string>
     */
    public function origins(): array
    {
        return FrontendKeyOrigins::parse((string) $this->string('origins'));
    }
}
