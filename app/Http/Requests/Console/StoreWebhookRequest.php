<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Http\Controllers\Console\WebhookController;
use App\Platform\Console\WebhookEventCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

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
     * The events are the picker's, and only the picker's: an option the form does not
     * list is still POSTable, and a subscription to an event nothing emits is an endpoint
     * waiting for deliveries that cannot come.
     *
     * @return array<string, list<string|In>>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'url', 'max:500'],
            'eventTypes' => ['required', 'array', 'min:1'],
            'eventTypes.*' => ['string', Rule::in(WebhookEventCatalogue::offered())],
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
            'eventTypes.*.in' => WebhookEventCatalogue::REFUSAL,
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
