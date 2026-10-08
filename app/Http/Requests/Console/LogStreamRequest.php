<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Platform\Integrations\LogStreamDestinations;
use Cbox\LaravelSiem\Enums\AuthScheme;
use Cbox\LaravelSiem\Enums\Destination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A SIEM export stream, as the console's form sends it — new, or edited.
 *
 * WHOSE TRAIL IT SHIPS IS NOT A FIELD. The plane decides — a stream created on the
 * environment plane carries every organization's entries, one created on the organization
 * plane carries that tenant's alone — so there is no control here and nothing to validate.
 *
 * The destination and the auth scheme are validated against their enums rather than
 * trusted: without the rules `tryFrom()` answers null and the console reports "choose a
 * valid destination" for a value it was never offered, which is a refusal that reads as a
 * bug in the form. An endpoint URL and a scheme are an HTTP collector's; the cloud
 * destinations derive their endpoint and authenticate their own way, so for them both are
 * optional (a custom endpoint is how an S3-compatible store is reached). What each option
 * may hold is the action's to check, and the package's — this only shapes the form's
 * strings into the action's input.
 */
final class LogStreamRequest extends FormRequest
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
        $collector = fn (): bool => ! (Destination::tryFrom((string) $this->string('destination'))?->requiresOptions() ?? false);

        return [
            'name' => ['required', 'string', 'max:190'],
            'destination' => ['required', Rule::enum(Destination::class)],
            // `url`, because this platform posts your audit trail to the address.
            'endpointUrl' => [Rule::requiredIf($collector), 'nullable', 'url', 'max:2048'],
            'scheme' => [Rule::requiredIf($collector), 'nullable', Rule::enum(AuthScheme::class)],
            /*
             * OPTIONAL, and that is the whole point of the HMAC scheme: leave it empty and
             * a signing key is generated and revealed once. A required rule here would
             * make the generated-key path unreachable from the form that advertises it.
             * On an edit, empty keeps the stored credential. Long enough for a Google
             * service-account key file.
             */
            'secret' => ['nullable', 'string', 'max:8192'],
            'options' => ['nullable', 'array'],
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
        return AuthScheme::tryFrom((string) $this->string('scheme')) ?? $this->destination()->defaultAuth();
    }

    /** Null asks the registry to generate one, or on an edit keeps it. NOT trimmed — it is a credential. */
    public function secret(): ?string
    {
        $secret = (string) $this->string('secret');

        return $secret !== '' ? $secret : null;
    }

    /**
     * The destination's options as the action takes them ({@see LogStreamDestinations::fromForm()}).
     *
     * @return array<string, mixed>
     */
    public function options(Destination $destination): array
    {
        return LogStreamDestinations::fromForm($destination, $this->input('options'));
    }
}
