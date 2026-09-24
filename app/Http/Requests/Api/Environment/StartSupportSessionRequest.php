<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Environment;

use Cbox\Id\OAuthServer\Enums\SupportActorKind;
use Cbox\Id\OAuthServer\ValueObjects\NewSupportSession;
use Cbox\Id\OAuthServer\ValueObjects\SupportCodeRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/support-sessions` — a member of your staff signs in to your app AS one of a
 * customer's users, for a stated reason and at most an hour.
 *
 * `actor_user_id` is the staff member, and is required: a support session is a PERSON
 * acting, and this key is not one. The framework authorizes them — they must hold your
 * app's own `support:impersonate` permission through an environment-wide (staff) grant.
 *
 * Send `redirect_uri` with a PKCE `code_challenge` (S256) to receive the session's first
 * authorization code in the response; redeem it at `/oauth/token` with the verifier.
 */
final class StartSupportSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'string', 'max:64'],
            'organization_id' => ['required', 'string', 'max:64'],
            'client_id' => ['required', 'string', 'max:255'],
            'actor_user_id' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:500'],
            'ttl_minutes' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'scopes' => ['sometimes', 'array', 'max:50'],
            'scopes.*' => ['string', 'max:128'],
            'redirect_uri' => ['required_with:code_challenge', 'string', 'max:2048'],
            // S256 of a 32-byte verifier, base64url without padding (RFC 7636 §4.2).
            'code_challenge' => ['required_with:redirect_uri', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'nonce' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['code_challenge.regex' => 'The code_challenge must be an S256 PKCE challenge: 43 base64url characters.'];
    }

    public function toSession(): NewSupportSession
    {
        return new NewSupportSession(
            actorId: $this->string('actor_user_id')->toString(),
            actorKind: SupportActorKind::Staff,
            targetUserId: $this->string('user_id')->toString(),
            organizationId: $this->string('organization_id')->toString(),
            clientId: $this->string('client_id')->toString(),
            reason: $this->string('reason')->toString(),
            scopes: array_values(array_filter((array) $this->input('scopes', []), 'is_string')),
            ttlSeconds: $this->filled('ttl_minutes') ? $this->integer('ttl_minutes') * 60 : null,
        );
    }

    public function codeRequest(): ?SupportCodeRequest
    {
        if (! $this->filled('redirect_uri')) {
            return null;
        }

        return new SupportCodeRequest(
            redirectUri: $this->string('redirect_uri')->toString(),
            codeChallenge: $this->string('code_challenge')->toString(),
            nonce: $this->filled('nonce') ? $this->string('nonce')->toString() : null,
        );
    }
}
