<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An app's scopes: the boxes ticked in its picker, and the scopes typed under Advanced.
 *
 * THE SCOPES ARE EDITABLE ON A LIVE APP, which they once were not: the only way to add one
 * was to delete the app and register a new one — taking its client id and secret, and
 * every integration holding them, to add a string to a list.
 *
 * What the boxes may carry is bounded by the controller against what the page could have
 * offered; the typed ones are free text by design, and the framework refuses on save any
 * that names a registered API's scope this app may not hold.
 */
final class SaveClientScopesRequest extends FormRequest
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
            'scopes' => ['array'],
            'scopes.*' => ['string', 'max:128'],
            'customScopes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The ticked boxes, as sent — strings only.
     *
     * @return list<string>
     */
    public function chosen(): array
    {
        return array_values(array_filter((array) $this->input('scopes', []), 'is_string'));
    }

    /**
     * The scopes typed under Advanced: comma- or space-separated, trimmed, no empties.
     *
     * @return list<string>
     */
    public function custom(): array
    {
        $typed = preg_split('/[\s,]+/', (string) $this->string('customScopes')) ?: [];

        return array_values(array_filter(array_map('trim', $typed), static fn (string $scope): bool => $scope !== ''));
    }
}
