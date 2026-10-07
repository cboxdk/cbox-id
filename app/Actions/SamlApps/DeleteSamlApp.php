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

/**
 * Remove a SAML application: nobody can sign in to it with their account here any more.
 */
#[AsAction(
    name: 'saml_apps.delete',
    summary: 'Remove a SAML application. People can no longer sign in to it with their account here.',
    scope: 'saml_apps:write',
    danger: Danger::Critical,
    tag: 'SAML apps',
    rest: ['DELETE', '/saml-apps/{id}'],
    status: 204,
    consoleRoutes: ['environment.sso-providers.destroy'],
)]
final readonly class DeleteSamlApp implements Action
{
    public function __construct(
        private ServiceProviders $providers,
        private SignInAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The SAML application\'s id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $provider = $this->providers->findById($context->string('id')) ?? throw ActionRefused::notFound('SAML application');

        $provider->delete();

        $this->audit->record(SignInAudit::SAML_APP_DELETED, $context->actor(), null, 'saml_app', $provider->id, [
            'entity_id' => $provider->entity_id,
        ]);

        return ActionResult::none($provider);
    }
}
