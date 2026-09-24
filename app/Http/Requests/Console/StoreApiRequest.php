<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Cbox\Id\OAuthServer\Support\ResourceIndicator;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Register an API: its name, the identifier its tokens will name as their audience, who
 * owns it, and optionally the app whose roles and permissions it enforces.
 *
 * THE IDENTIFIER IS FOR LIFE. Every token minted for the API carries it in `aud`, and the
 * API checks for it, so changing it would invalidate every token in flight and every
 * resource server's configuration at once. The form says so, and nothing edits it later.
 */
final class StoreApiRequest extends FormRequest
{
    /** The console decides who may register an API; the controller asks it. */
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
            'name' => ['required', 'string', 'max:120'],
            'identifier' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! ResourceIndicator::isWellFormed($value)) {
                        $fail('Use an absolute URL with a host and no fragment, at most 255 characters — for example https://api.example.com.');
                    }
                },
            ],
            'owner' => ['required', 'string', 'max:64'],
            'clientId' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function identifier(): string
    {
        return (string) $this->string('identifier');
    }

    /** `environment`, or the id of the organization chosen in the console. */
    public function owner(): string
    {
        return (string) $this->string('owner');
    }

    public function clientId(): ?string
    {
        $clientId = trim((string) $this->string('clientId'));

        return $clientId === '' ? null : $clientId;
    }
}
