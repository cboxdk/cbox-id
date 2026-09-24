<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Where this app is told that somebody signed out — OIDC Back-Channel Logout — and
 * whether it needs the session id to act on it. Empty stops the notifications.
 *
 * The URI's own rules (HTTPS, or HTTP on localhost; no fragment; no credentials) are the
 * framework's, applied when the app is saved: stating them twice is how a form and the
 * thing behind it come to disagree.
 */
final class SaveBackchannelLogoutRequest extends FormRequest
{
    /** WHICH app is in the URL, and the controller re-resolves it against the scope. */
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
            'uri' => ['nullable', 'string', 'max:2000'],
            'sessionRequired' => ['boolean'],
        ];
    }

    public function logoutUri(): ?string
    {
        $uri = trim((string) $this->string('uri'));

        return $uri === '' ? null : $uri;
    }

    public function sessionRequired(): bool
    {
        return $this->boolean('sessionRequired');
    }
}
