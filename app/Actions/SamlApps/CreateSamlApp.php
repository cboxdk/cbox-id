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
use Cbox\Id\SamlIdp\ValueObjects\NewServiceProvider;

/**
 * Register a SAML application: somebody ELSE'S system that trusts this environment to say
 * who a person is, and is then sent an assertion naming them, carrying the attributes
 * mapped here, to the ACS URL given here. Critical, because it decides where people's
 * identities are sent.
 *
 * The other direction from an SSO connection, which lets people sign in HERE with an
 * account elsewhere. The entity id is unique per environment, and a second registration of
 * one is refused rather than left to the database to 500 on.
 */
#[AsAction(
    name: 'saml_apps.create',
    summary: 'Register a SAML application that signs people in with their account here. Decides where assertions — and their attributes — are sent.',
    scope: 'saml_apps:write',
    danger: Danger::Critical,
    schema: 'SamlApp',
    tag: 'SAML apps',
    rest: ['POST', '/saml-apps'],
    status: 201,
    consoleRoutes: ['environment.sso-providers.store'],
)]
final readonly class CreateSamlApp implements Action
{
    public function __construct(
        private ServiceProviders $providers,
        private SignInAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('entity_id')->required()->max(500)->describe('The application\'s SAML EntityID, unique in this environment.'),
            ...array_map(
                static fn (Field $field): Field => $field->name === 'acs_url' ? $field->required() : $field,
                SamlAppFields::fields(),
            ),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $entityId = trim($context->string('entity_id'));
        $acsUrl = SamlAppFields::assertAcsUrl($context->string('acs_url'));
        $certificate = $context->nullableString('certificate');
        $certificate = $certificate === null || trim($certificate) === '' ? null : trim($certificate);
        $signed = $context->boolean('want_authn_requests_signed');

        if ($entityId === '' || $this->providers->findByEntityId($entityId) !== null) {
            throw ActionRefused::because('entity_id_taken', 'A SAML application with this entity id is already registered in this environment.', 'entity_id');
        }

        SamlAppFields::assertVerifiable($signed, $certificate);

        $nameIdAttribute = trim($context->string('name_id_attribute'));

        $provider = $this->providers->register(new NewServiceProvider(
            entityId: $entityId,
            acsUrl: $acsUrl,
            nameIdFormat: $context->has('name_id_format') ? NameIdFormat::from($context->string('name_id_format')) : NameIdFormat::EmailAddress,
            nameIdAttribute: $nameIdAttribute === '' ? 'email' : $nameIdAttribute,
            attributeMappings: SamlAppFields::mappings($context->array('attribute_mappings')),
            certificate: $certificate,
            wantAuthnRequestsSigned: $signed,
        ));

        $this->audit->record(SignInAudit::SAML_APP_REGISTERED, $context->actor(), null, 'saml_app', $provider->id, [
            'entity_id' => $provider->entity_id,
            'acs_url' => $provider->acs_url,
        ]);

        return ActionResult::item($provider, SamlAppFields::present($provider));
    }
}
