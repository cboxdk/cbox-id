<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Rules\NotBreached;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new platform operator — an identity that sits ABOVE every environment.
 *
 * A FIXED FLOOR rather than the tenant password policy, and deliberately: an operator
 * belongs to no environment, so no tenant's `AuthPolicy` governs them and there is nothing
 * to inherit. They are also the most privileged accounts on the install, which is why the
 * breach screen is not optional here — the one place in the product where "it is only a
 * staff account" would be exactly backwards.
 */
final class CreateOperatorRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:12', 'max:200', new NotBreached],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function email(): string
    {
        return trim((string) $this->string('email'));
    }

    public function password(): string
    {
        return (string) $this->string('password');
    }
}
