<?php

declare(strict_types=1);

namespace App\Actions\CustomerApiKeys;

use App\Actions\Organizations\OrganizationFields;
use App\Http\Resources\Environment\CustomerApiKeyResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Kernel\Tenancy\GenericTenant;
use Cbox\Id\Organization\Models\CustomerApiKey;

/**
 * The API keys an organization's people created for your apps' APIs, a page at a time —
 * whose each is, which app it is for, what it may do, when it was last used. Never the key
 * material: a key is shown to its holder once, when it is made.
 *
 * Tenant-owned: read as this organization, and bound to it in the WHERE clause too.
 */
#[AsAction(
    name: 'api_keys.list',
    summary: 'List the API keys an organization\'s members created for your apps, a page at a time. Never the key material.',
    scope: 'api_keys:read',
    danger: Danger::Read,
    schema: 'ApiKey',
    tag: 'API keys',
    rest: ['GET', '/organizations/{organization_id}/api-keys'],
)]
final readonly class ListCustomerApiKeys implements Action
{
    use Paginates;

    public function __construct(private TenantContext $tenants) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('client_id')->max(255)->describe('Only the keys for this app\'s API.'),
            ...self::pageFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));
        $clientId = $context->nullableString('client_id');

        return $this->tenants->runAs(GenericTenant::of($organization->id), fn (): ActionResult => $this->page(
            CustomerApiKey::query()
                ->where('organization_id', $organization->id)
                ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId)),
            $context,
            CustomerApiKeyResource::from(...),
        ));
    }
}
