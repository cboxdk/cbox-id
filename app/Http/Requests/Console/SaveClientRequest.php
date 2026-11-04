<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Rules\SecureUriLines;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An edit to an app's details: its name, and where it may return people to.
 *
 * The scopes are edited on the app's Scopes tab ({@see SaveClientScopesRequest}), not
 * here: a details form that also carried them would write back whatever that tab held
 * when this one loaded.
 *
 * WHAT THIS DOES NOT EDIT: the kind, the grants and the client type. Those decide how the
 * app authenticates, and changing one under a running integration is not an edit, it is a
 * different app.
 */
final class SaveClientRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:190'],
            'redirectUris' => ['nullable', 'string', 'max:2000', new SecureUriLines(
                __('console.each_redirect_uri_must_use_https_http_is_all_5445c31e'),
            )],
            'postLogoutRedirectUris' => ['nullable', 'string', 'max:2000', new SecureUriLines(
                __('console.each_sign_out_uri_must_use_https_http_is_all_b9c71673'),
            )],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    /** @return list<string> */
    public function redirectUris(): array
    {
        return SecureUriLines::split((string) $this->string('redirectUris'));
    }

    /** @return list<string> */
    public function postLogoutRedirectUris(): array
    {
        return SecureUriLines::split((string) $this->string('postLogoutRedirectUris'));
    }
}
