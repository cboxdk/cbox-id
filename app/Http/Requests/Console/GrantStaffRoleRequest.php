<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A staff role for one person, named by their email address.
 *
 * By address rather than by a picker of every user: an environment's user list is
 * unbounded, and the address is what the person granting it actually knows.
 */
final class GrantStaffRoleRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:254'],
            'role' => ['required', 'string', 'max:64'],
        ];
    }

    public function email(): string
    {
        return mb_strtolower(trim((string) $this->string('email')));
    }

    public function roleId(): string
    {
        return (string) $this->string('role');
    }
}
