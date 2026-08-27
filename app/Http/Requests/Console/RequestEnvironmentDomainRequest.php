<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Cbox\Id\Platform\Contracts\EnvironmentDomains;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A custom domain to serve an environment's identity endpoints on.
 *
 * `max:253` is the DNS limit on a fully-qualified name, and that is all the shape rule
 * this can honestly be: whether a given string is a domain THIS deployment may serve is a
 * question about base domains, reserved names and what is already claimed, and
 * {@see EnvironmentDomains::request()} is the one place that
 * knows. Its refusal carries a sentence naming what is wrong, which a regex here could
 * only replace with "invalid format".
 */
final class RequestEnvironmentDomainRequest extends FormRequest
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
            'domain' => ['required', 'string', 'max:253'],
        ];
    }

    public function environmentId(): string
    {
        return trim((string) $this->string('environment'));
    }

    public function domain(): string
    {
        return trim((string) $this->string('domain'));
    }
}
