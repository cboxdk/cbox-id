<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Http\Controllers\Console\WebhookController;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registering an endpoint.
 *
 * The URL is validated here and again at the registry's SSRF guard, and both are load
 * bearing: this one refuses a malformed address, and that one refuses a well-formed
 * address that resolves somewhere private. Neither replaces the other.
 *
 * `environmentWide` is deliberately NOT validated into an authorization. It is a request
 * parameter, so anybody can send it; whether it is honoured is decided by the plane, in
 * {@see WebhookController::targetOrganizationId()}.
 */
final class StoreWebhookRequest extends FormRequest
{
    /**
     * The route's middleware stack has already established that this administrator may
     * administer the console. What may be REGISTERED, and for whom, is a question about
     * the plane rather than about the request body.
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
