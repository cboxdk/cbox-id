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
use Cbox\Id\SamlIdp\Contracts\ServiceProviders;

/**
 * One SAML app. The registry is environment-scoped, so an id from another
 * environment resolves to nothing — a 404, never a cross-tenant read.
 */
#[AsAction(
    name: 'saml_apps.get',
    summary: 'Read one SAML app: its entity id, ACS URL, NameID and attribute mappings. Never its certificate.',
    scope: 'saml_apps:read',
    danger: Danger::Read,
    schema: 'SamlApp',
    tag: 'SAML apps',
    rest: ['GET', '/saml-apps/{id}'],
)]
final readonly class ShowSamlApp implements Action
{
    public function __construct(private ServiceProviders $providers) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The SAML app\'s id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $provider = $this->providers->findById($context->string('id')) ?? throw ActionRefused::notFound('SAML app');

        return ActionResult::item($provider, SamlAppFields::present($provider));
    }
}
