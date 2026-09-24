<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Platform\SupportAccess\Contracts\SupportAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Sign in to <app> as <user>": which app, in which of their organizations, why, and for
 * how long.
 *
 * The reason is required here AND by the framework: it is the only account of the session
 * the organization gets, on its activity log and in its webhook, so a blank one is a
 * support session nobody can later explain.
 */
final class StartSupportSessionRequest extends FormRequest
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
            'app' => ['required', 'string', 'max:64'],
            'organization' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:500'],
            // Never longer than the framework will grant: the configured maximum.
            'minutes' => ['required', 'integer', 'min:1', 'max:'.app(SupportAccess::class)->maxMinutes()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why — the organization sees this on its activity log.',
            'minutes.max' => 'A support session lasts at most :max minutes.',
        ];
    }

    public function clientId(): string
    {
        return (string) $this->string('app');
    }

    public function organizationId(): string
    {
        return (string) $this->string('organization');
    }

    public function reason(): string
    {
        return trim((string) $this->string('reason'));
    }

    public function minutes(): int
    {
        return $this->integer('minutes');
    }
}
