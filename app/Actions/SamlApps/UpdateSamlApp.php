<?php

declare(strict_types=1);

namespace App\Actions\SamlApps;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\SignInAudit;
use Cbox\Id\SamlIdp\Contracts\ServiceProviders;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;

/**
 * Change a SAML app. A field left out keeps its value; `attribute_mappings`, when
 * sent, is the complete list afterwards.
 *
 * The certificate is only ever REPLACED: a blank or absent one keeps what is on file rather
 * than wiping it, which would silently turn off the verification the signed-requests flag
 * says is happening.
 */
#[AsAction(
    name: 'saml_apps.update',
    summary: 'Change a SAML app\'s entity id, ACS URL, NameID, attribute mappings, signing certificate or owning organization.',
    scope: 'saml_apps:write',
    danger: Danger::Critical,
    schema: 'SamlApp',
    tag: 'SAML apps',
    rest: ['PATCH', '/saml-apps/{id}'],
    consoleRoutes: ['environment.sso-providers.update'],
)]
final readonly class UpdateSamlApp implements Action
{
    public function __construct(
        private ServiceProviders $providers,
        private SignInAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The SAML app\'s id.'),
            Field::string('entity_id')->max(500)->describe('The application\'s SAML EntityID, unique in this environment.'),
            ...SamlAppFields::fields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $provider = $this->providers->findById($context->string('id')) ?? throw ActionRefused::notFound('SAML app');

        if ($context->has('entity_id')) {
            $entityId = trim($context->string('entity_id'));
            $holder = $entityId === '' ? null : $this->providers->findByEntityId($entityId);

            if ($entityId === '' || ($holder !== null && $holder->id !== $provider->id)) {
                throw ActionRefused::because('entity_id_taken', 'A SAML app with this entity id is already registered in this environment.', 'entity_id');
            }

            $provider->entity_id = $entityId;
        }

        if ($context->has('acs_url')) {
            $provider->acs_url = SamlAppFields::assertAcsUrl($context->string('acs_url'));
        }

        if ($context->has('name_id_format')) {
            $provider->name_id_format = NameIdFormat::from($context->string('name_id_format'));
        }

        if ($context->has('name_id_attribute') && trim($context->string('name_id_attribute')) !== '') {
            $provider->name_id_attribute = trim($context->string('name_id_attribute'));
        }

        if ($context->has('attribute_mappings')) {
            $provider->attribute_mappings = SamlAppFields::mappings($context->array('attribute_mappings'));
        }

        if ($context->has('want_authn_requests_signed')) {
            $provider->want_authn_requests_signed = $context->boolean('want_authn_requests_signed');
        }

        // Sent as null, the application becomes environment-wide again; left out, it keeps
        // whichever organization owns it.
        if ($context->has('organization_id')) {
            $provider->organization_id = SamlAppFields::organization($context);
        }

        $certificate = trim((string) $context->nullableString('certificate'));

        if ($certificate !== '') {
            $provider->certificate = $certificate;
        }

        SamlAppFields::assertVerifiable($provider->want_authn_requests_signed, $provider->certificate);

        $changed = array_keys($provider->getDirty());
        $provider->save();

        if ($changed !== []) {
            $this->audit->record(SignInAudit::SAML_APP_UPDATED, $context->actor(), null, 'saml_app', $provider->id, [
                'entity_id' => $provider->entity_id,
                // Which fields, never the certificate's value.
                'changed' => $changed,
                'organization_id' => $provider->organization_id,
            ]);
        }

        return ActionResult::item($provider, SamlAppFields::present($provider));
    }
}
