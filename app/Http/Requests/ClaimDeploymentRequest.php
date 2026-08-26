<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Platform\PlaneResolver;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The one submission that claims an unclaimed deployment.
 *
 * THE ORGANIZATION NAME IS CONDITIONAL ON THE SHAPE, and the shape is the deployment's own
 * — never the form's. A multi-tenant install creates the first customer, so it needs one; a
 * single-host install has no customer layer to name, and demanding one there would be
 * asking for a thing that does not exist.
 *
 * The SETUP TOKEN is validated as present here and matched in the controller, because
 * matching it is rate-limited and a limiter belongs where a refusal can be counted.
 */
final class ClaimDeploymentRequest extends FormRequest
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
            'token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            // A FIXED FLOOR, not a tenant policy: there is no environment yet to have one.
            'password' => ['required', 'string', 'min:12'],
            'environmentName' => ['required', 'string', 'max:190'],
            'organizationName' => [
                app(PlaneResolver::class)->isMultiTenant() ? 'required' : 'nullable',
                'string',
                'max:190',
            ],
        ];
    }

    public function token(): string
    {
        return (string) $this->string('token');
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

    public function environmentName(): string
    {
        return trim((string) $this->string('environmentName'));
    }

    /** Falls back to the operator's own name, which is what a one-person install means. */
    public function organizationName(): string
    {
        $organization = trim((string) $this->string('organizationName'));

        return $organization !== '' ? $organization : $this->name();
    }
}
