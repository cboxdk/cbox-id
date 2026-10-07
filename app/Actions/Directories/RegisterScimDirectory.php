<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Directory\Contracts\Directories;

/**
 * Register a SCIM directory for an organization: an endpoint its identity provider
 * provisions people into, and the bearer token that authenticates every call it makes.
 *
 * Critical, and the token is in `redact`: it creates, changes and deactivates the
 * organization's people. It is shown in this answer only — only its hash is stored — and
 * an idempotent replay returns everything but the token. The console asks for a fresh
 * password before it gets here, for the same reason.
 */
#[AsAction(
    name: 'directories.create',
    summary: 'Register a SCIM directory for an organization. Answers the SCIM base URL and a bearer token, shown once.',
    scope: 'directory_sync:write',
    danger: Danger::Critical,
    schema: 'Directory',
    tag: 'Directory sync',
    rest: ['POST', '/directories'],
    status: 201,
    consoleRoutes: ['directories.store', 'environment.directories.store'],
    consoleGate: ConsoleGate::Administer,
    redact: ['bearer_token'],
)]
final readonly class RegisterScimDirectory implements Action
{
    public function __construct(
        private Directories $directories,
        private EnterpriseAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->required()->max(64)->describe('The organization whose people it provisions.'),
            Field::string('name')->required()->max(120)->describe('What administrators call it: "Okta SCIM".'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = EnterpriseReach::requiredOrganization($context);

        EnterpriseReach::assertEntitled($organizationId, 'scim');

        $registered = $this->directories->register($organizationId, trim($context->string('name')));
        // Re-read: the registry leaves the provider and status to their column defaults, so
        // the model it hands back does not carry them yet.
        $directory = $registered->directory->refresh();

        $this->audit->record(EnterpriseAudit::DIRECTORY_REGISTERED, $context->actor(), $organizationId, 'directory', $directory->id, [
            'name' => $directory->name,
            'provider' => $directory->provider->value,
        ]);

        return ActionResult::item($registered, DirectoryFields::present($directory, $registered->token));
    }
}
