<?php

declare(strict_types=1);

namespace App\Actions\SamlApps;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use Cbox\Id\SamlIdp\Models\ServiceProvider;

#[AsAction(
    name: 'saml_apps.list',
    summary: 'List the SAML apps that trust this environment as their identity provider.',
    scope: 'saml_apps:read',
    danger: Danger::Read,
    schema: 'SamlApp',
    tag: 'SAML apps',
    rest: ['GET', '/saml-apps'],
)]
final class ListSamlApps implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of(self::pageFields());
    }

    public function handle(ActionContext $context): ActionResult
    {
        return $this->page(ServiceProvider::query(), $context, SamlAppFields::present(...));
    }
}
