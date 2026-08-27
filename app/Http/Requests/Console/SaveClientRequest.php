<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Platform\ScopeCatalog;
use App\Rules\SecureUriLines;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An edit to an app that already exists: its name, where it may return people to, and the
 * scopes it is registered for.
 *
 * THE SCOPES ARE EDITABLE, which they were not. The page showed them as read-only badges,
 * so the only way to add one to a live app was to delete it and register a new one —
 * taking its client id and secret, and every integration holding them, to add a string to
 * a list.
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
                'Each redirect URI must use https (http is allowed only on localhost) — e.g. https://app.example.com/callback.',
            )],
            'postLogoutRedirectUris' => ['nullable', 'string', 'max:2000', new SecureUriLines(
                'Each sign-out URI must use https (http is allowed only on localhost) — e.g. https://app.example.com/signed-out.',
            )],
            'scopes' => ['array'],
            'scopes.*' => ['string'],
            'customScopes' => ['nullable', 'string', 'max:500'],
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

    /**
     * Catalogue scopes from the picker, plus any custom keys typed by hand.
     *
     * Intersected with the catalogue rather than trusted: the picker is a list of
     * checkboxes and the payload is a list of strings, and only one of those is bounded.
     *
     * @return list<string>
     */
    public function scopes(ScopeCatalog $catalog): array
    {
        /** @var list<string> $chosen */
        $chosen = array_values(array_filter((array) $this->input('scopes', []), 'is_string'));

        $custom = array_values(array_filter(
            array_map('trim', explode(',', (string) $this->string('customScopes'))),
            static fn (string $scope): bool => $scope !== '',
        ));

        return array_values(array_unique(array_merge(
            array_values(array_intersect($chosen, $catalog->keys())),
            $custom,
        )));
    }
}
