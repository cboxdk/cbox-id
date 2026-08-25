<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Changing an endpoint's address and what it hears about.
 *
 * The URL is validated here and again against the SSRF guard in the controller, and both
 * are load bearing: this one refuses a malformed address, and that one refuses a
 * well-formed address that resolves somewhere private. A public endpoint must never be
 * silently repointed at an internal one, which is exactly what an update could otherwise
 * do to an endpoint that passed the check when it was created.
 */
final class UpdateWebhookRequest extends FormRequest
{
    /**
     * The route's middleware stack has already established that this administrator may
     * administer the console; WHICH endpoint they may change is a question about that
     * endpoint's owner, answered in the controller where the endpoint is resolved.
     */
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
            'url' => ['required', 'url', 'max:500'],
            'eventTypes' => ['required', 'array', 'min:1'],
            'eventTypes.*' => ['string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'eventTypes.required' => 'Choose at least one event for this endpoint to receive.',
            'eventTypes.min' => 'Choose at least one event for this endpoint to receive.',
        ];
    }

    public function url(): string
    {
        return (string) $this->string('url');
    }

    /**
     * The subscribed events, as a gapless list.
     *
     * `array_values` because the keys are whatever the request sent — a browser
     * serialising a partially-unchecked list produces gaps, and the registry stores this
     * as JSON where a gapped array becomes an object.
     *
     * @return list<string>
     */
    public function eventTypes(): array
    {
        /** @var array<array-key, string> $types */
        $types = $this->array('eventTypes');

        return array_values($types);
    }
}
