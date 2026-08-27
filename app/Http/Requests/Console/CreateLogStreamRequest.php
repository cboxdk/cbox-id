<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Cbox\LaravelSiem\Enums\AuthScheme;
use Cbox\LaravelSiem\Enums\Destination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new SIEM export stream.
 *
 * WHOSE TRAIL IT SHIPS IS NOT A FIELD. The plane decides — a stream created on the
 * environment plane carries every organization's entries, one created on the organization
 * plane carries that tenant's alone — so there is no control here and nothing to validate.
 *
 * The destination and the auth scheme are validated against their enums rather than
 * trusted: without the rules `tryFrom()` answers null and the console reports "choose a
 * valid destination" for a value it was never offered, which is a refusal that reads as a
 * bug in the form.
 */
final class CreateLogStreamRequest extends FormRequest
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
            'destination' => ['required', Rule::enum(Destination::class)],
            // `url`, because this platform posts your audit trail to the address.
            'endpointUrl' => ['required', 'url', 'max:2048'],
            'scheme' => ['required', Rule::enum(AuthScheme::class)],
            /*
             * OPTIONAL, and that is the whole point of the HMAC scheme: leave it empty and
             * a signing key is generated and revealed once. A required rule here would
             * make the generated-key path unreachable from the form that advertises it.
             */
            'secret' => ['nullable', 'string', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['endpointUrl' => 'endpoint URL'];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function destination(): Destination
    {
        return Destination::from((string) $this->string('destination'));
    }

    public function endpointUrl(): string
    {
        return trim((string) $this->string('endpointUrl'));
    }

    public function scheme(): AuthScheme
    {
        return AuthScheme::from((string) $this->string('scheme'));
    }

    /** Null asks the registry to generate one. NOT trimmed — it is a credential. */
    public function secret(): ?string
    {
        $secret = (string) $this->string('secret');

        return $secret !== '' ? $secret : null;
    }
}
