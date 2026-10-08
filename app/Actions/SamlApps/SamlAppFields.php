<?php

declare(strict_types=1);

namespace App\Actions\SamlApps;

use App\Http\Resources\Environment\Timestamp;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\OrganizationTarget;
use App\Rules\SecureRedirectUri;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;
use Cbox\Id\SamlIdp\Models\ServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * SAML applications — service providers that trust this environment as their identity
 * provider — as the management API takes and returns them. A helper, not an action.
 *
 * THE SIGNING CERTIFICATE IS WRITE-ONLY. It is never returned; `has_certificate` says
 * whether one is on file, and leaving it out of a change keeps the one there is.
 *
 * AN APPLICATION IS EITHER ONE ORGANIZATION'S OR EVERYBODY'S. With `organization_id` set,
 * the identity provider asserts only active members of that organization to it and refuses
 * everyone else (`saml_idp.assertion_refused` on the trail). Null is environment-wide: any
 * person in the environment can single-sign-on into it — which is how every application
 * behaved before the field existed, and is rarely what a customer's own app should be.
 */
final class SamlAppFields
{
    /**
     * @return list<Field>
     */
    public static function fields(): array
    {
        return [
            Field::string('acs_url')->max(1000)->format('uri')->describe('Where assertions are posted: https (http only on localhost), absolute, no fragment.'),
            Field::string('name_id_format')->oneOf(array_map(static fn (NameIdFormat $format): string => $format->value, NameIdFormat::cases()))->describe('The NameID format URN. Default emailAddress.'),
            Field::string('name_id_attribute')->max(120)->describe('The person\'s field the NameID is read from. Default `email`.'),
            Field::list('attribute_mappings', Field::object('mapping', [
                Field::string('attribute')->required()->max(190)->distinct()->describe('The attribute name the assertion emits: `email`, `displayName`.'),
                Field::string('field')->required()->max(190)->describe('The person\'s field it is read from: `email`, `name`.'),
            ]))->max(100)->describe('The attributes the assertion carries. Sent, it is the COMPLETE list afterwards.'),
            Field::boolean('want_authn_requests_signed')->describe('Verify the application\'s signature on every AuthnRequest. Needs a certificate.'),
            Field::string('certificate')->nullable()->max(20000)->describe('The application\'s signing certificate (PEM). Write-only; left out or null keeps the one on file.'),
            Field::string('organization_id')->nullable()->max(64)->describe('The organization that owns the application: only its active members are signed in to it, and the assertion names it. Null makes it environment-wide — every person in the environment may sign in to it.'),
        ];
    }

    /**
     * The organization an application is for, or null for one every person in the
     * environment may sign in to — checked like every other organization an action names.
     *
     * Blank is null: the console's "every organization" choice is an empty value, and an
     * empty string stored as an owner would make `isOrganizationOwned()` answer no for a row
     * that looks owned.
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     */
    public static function organization(ActionContext $context): ?string
    {
        $organizationId = trim((string) $context->nullableString('organization_id'));

        return OrganizationTarget::check($context, $organizationId === '' ? null : $organizationId);
    }

    /** @throws ActionRefused */
    public static function assertAcsUrl(string $url): string
    {
        $url = trim($url);

        if (! SecureRedirectUri::isSecure($url)) {
            throw ActionRefused::because('insecure_acs_url', 'The ACS URL must use https (http is allowed only on localhost), be absolute, and carry no fragment.', 'acs_url');
        }

        return $url;
    }

    /**
     * A SIGNED-REQUEST APPLICATION IS USELESS WITHOUT A CERTIFICATE TO VERIFY AGAINST.
     *
     * Refused rather than saved, because the half-configured combination does not fail
     * loudly: the flag says requests are verified and nothing verifies them, which is worse
     * than never having turned it on.
     *
     * @throws ActionRefused
     */
    public static function assertVerifiable(bool $signed, ?string $certificate): void
    {
        if ($signed && $certificate === null) {
            throw ActionRefused::because('certificate_required', 'A signing certificate is required for signed AuthnRequests.', 'certificate');
        }
    }

    /**
     * Rows of `{attribute, field}` as the map the framework stores: blank rows dropped,
     * everything trimmed — an attribute mapped to nothing would emit an empty claim, which
     * an application reads as "this person has no email".
     *
     * @param  array<mixed>  $rows
     * @return array<string, string>
     */
    public static function mappings(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['attribute'] ?? null) || ! is_string($row['field'] ?? null)) {
                continue;
            }

            $attribute = trim($row['attribute']);
            $field = trim($row['field']);

            if ($attribute !== '' && $field !== '') {
                $out[$attribute] = $field;
            }
        }

        return $out;
    }

    /**
     * The map as the rows the API takes, for a caller (or the console) to send back.
     *
     * @param  array<string, string>  $mappings
     * @return list<array{attribute: string, field: string}>
     */
    public static function rows(array $mappings): array
    {
        $rows = [];

        foreach ($mappings as $attribute => $field) {
            $rows[] = ['attribute' => (string) $attribute, 'field' => $field];
        }

        return $rows;
    }

    /**
     * One application — `SamlApp` in the spec.
     *
     * @return array<string, mixed>
     */
    public static function present(ServiceProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'entity_id' => $provider->entity_id,
            'acs_url' => $provider->acs_url,
            'name_id_format' => $provider->name_id_format->value,
            'name_id_attribute' => $provider->name_id_attribute,
            'attribute_mappings' => self::rows($provider->attribute_mappings),
            'want_authn_requests_signed' => $provider->want_authn_requests_signed,
            // WHETHER, never WHAT.
            'has_certificate' => $provider->certificate !== null,
            'organization_id' => $provider->isOrganizationOwned() ? $provider->organization_id : null,
            'status' => $provider->status->value,
            'created_at' => Timestamp::of($provider->created_at),
            'updated_at' => Timestamp::of($provider->updated_at),
        ];
    }
}
