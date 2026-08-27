<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new isolation plane.
 *
 * THE DOMAIN IS RECORDED, NOT ROUTED. No DNS proof has been shown for it here, and the
 * per-environment issuer trusts a custom domain only once `domain_verified_at` is stamped
 * — so writing it now would route the host while discovery kept advertising the fallback
 * issuer. Every conformant OIDC client (ours included) rejects that mismatch per RFC 8414
 * §3.3, and the plane would be silently unusable from the moment it was created. The
 * field is validated here so the operator is told what to go and verify; the controller
 * creates the environment without it.
 */
final class CreatePlatformEnvironmentRequest extends FormRequest
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
            'domain' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    /** Lowercased, because a hostname is case-insensitive and the uniqueness check is not. */
    public function domain(): ?string
    {
        $domain = trim((string) $this->string('domain'));

        return $domain === '' ? null : strtolower($domain);
    }
}
