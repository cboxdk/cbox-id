<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Rules\SecureRedirectUri;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A downstream SAML service provider's configuration — the register form and the edit form,
 * which ask for exactly the same thing.
 *
 * THE SIGNING CERTIFICATE IS WRITE-ONLY. It is never echoed back to the console; the page
 * says whether one is on file, and a blank field means "keep the one you have" rather than
 * "remove it". A form that round-tripped the certificate would put it in the page's props
 * — and therefore in the browser's history entry — for no reason at all.
 */
final class SaveServiceProviderRequest extends FormRequest
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
            'entityId' => ['required', 'string', 'max:500'],
            'acsUrl' => ['required', 'string', 'max:1000', new SecureRedirectUri],
            // Enum-authoritative: a hand-written `in:` list would silently fall behind a
            // format added to (or removed from) the framework.
            'nameIdFormat' => ['required', Rule::enum(NameIdFormat::class)],
            'nameIdAttribute' => ['required', 'string', 'max:120'],
            'attributeMappings' => ['array'],
            'attributeMappings.*.key' => ['nullable', 'string', 'max:190'],
            'attributeMappings.*.value' => ['nullable', 'string', 'max:190'],
            'wantAuthnRequestsSigned' => ['boolean'],
            'certificate' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['nameIdFormat' => 'Choose a supported NameID format.'];
    }

    public function entityId(): string
    {
        return trim((string) $this->string('entityId'));
    }

    public function acsUrl(): string
    {
        return trim((string) $this->string('acsUrl'));
    }

    /**
     * Safe to parse rather than tryFrom: the rule above is the same enum, so a value that
     * reached here is a case of it.
     */
    public function nameIdFormat(): NameIdFormat
    {
        return NameIdFormat::from((string) $this->string('nameIdFormat'));
    }

    public function nameIdAttribute(): string
    {
        return trim((string) $this->string('nameIdAttribute'));
    }

    /**
     * @return array<string, string>
     */
    public function attributeMappings(): array
    {
        return AttributeMappings::from((array) $this->input('attributeMappings', []));
    }

    public function wantAuthnRequestsSigned(): bool
    {
        return $this->boolean('wantAuthnRequestsSigned');
    }

    /** Null when the field was left blank — which means "keep what is on file". */
    public function certificate(): ?string
    {
        $certificate = trim((string) $this->string('certificate'));

        return $certificate === '' ? null : $certificate;
    }
}
