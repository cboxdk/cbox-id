<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The first organization and the first admin of a brand-new plane.
 *
 * The PASSWORD IS NOT VALIDATED HERE beyond a length ceiling, and that is deliberate: the
 * policy that governs it is the TARGET TENANT's, not whichever plane the operator console
 * happens to be sitting on, and it can only be asked inside that environment. The
 * controller asks it there, through the same `PasswordPolicyGuard` every other writer
 * uses, and reports the violation against this field.
 */
final class ProvisionEnvironmentAdminRequest extends FormRequest
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
            'orgName' => ['required', 'string', 'max:190'],
            'adminName' => ['required', 'string', 'max:190'],
            'adminEmail' => ['required', 'email', 'max:190'],
            'adminPassword' => ['required', 'string', 'max:200'],
        ];
    }

    public function orgName(): string
    {
        return trim((string) $this->string('orgName'));
    }

    public function adminName(): string
    {
        return trim((string) $this->string('adminName'));
    }

    public function adminEmail(): string
    {
        return trim((string) $this->string('adminEmail'));
    }

    public function adminPassword(): string
    {
        return (string) $this->string('adminPassword');
    }
}
