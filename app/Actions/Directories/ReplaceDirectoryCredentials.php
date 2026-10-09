<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Directory\Contracts\PullDirectories;
use Cbox\Id\Directory\DirectoryConnectors;
use Cbox\Id\Directory\Hris\HrisCatalog;

/**
 * Give a pull directory new provider credentials — the API key was rotated, the client
 * secret expired, the integration user's password changed — without disconnecting it and
 * losing its people, groups and role mappings.
 *
 * Verified against the provider before the old ones are replaced, so a typo never turns a
 * working sync into a failing one. Critical: it decides whose credentials provision and
 * deprovision the organization's people. Input only; nothing about them is returned, and
 * the trail records that they were replaced, never what with. The next run is a full pull.
 *
 * An HR system takes the keys its setup guide names; Google Workspace and Microsoft Entra
 * take the same fields `directories.connect` does.
 */
#[AsAction(
    name: 'directories.credentials.replace',
    summary: 'Replace a pull directory\'s provider credentials, verifying the new ones first. Write-only; never returned.',
    scope: 'directory_sync:write',
    danger: Danger::Critical,
    schema: 'Directory',
    tag: 'Directory Sync',
    rest: ['PUT', '/directories/{id}/credentials'],
    consoleRoutes: ['directories.credentials', 'environment.directories.credentials'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class ReplaceDirectoryCredentials implements Action
{
    public function __construct(
        private PullDirectories $directories,
        private DirectoryConnectors $connectors,
        private EnterpriseAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        $hris = HrisCatalog::credentialKeys();
        $properties = [];

        foreach (array_unique([...$hris, 'service_account_json', 'admin_email', 'tenant_id']) as $key) {
            $properties[] = Field::string($key)->max(20000);
        }

        return InputSchema::of([
            Field::string('id')->inPath()->describe('The directory\'s id.'),
            EnterpriseReach::narrowField(),
            Field::object('credentials', $properties)->required()
                ->describe('The new credentials, in the keys the provider\'s setup names (Google: service_account_json, admin_email; Entra: tenant_id, client_id, client_secret). Write-only.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $directory = DirectoryFields::changeable($context);
        $provider = $directory->provider;

        if (! $provider->isPull()) {
            throw ActionRefused::because('not_pull', 'A SCIM directory has no provider credentials; rotate its bearer token instead.');
        }

        if ($provider->isHris()) {
            $credentials = HrisCredentialFields::verified($this->connectors, $provider, $context->array('credentials'));
        } else {
            $credentials = ConnectPullDirectory::credentialsFor($provider, $context->array('credentials'));

            if (! $this->connectors->has($provider) || ! $this->connectors->for($provider)->verify($credentials)) {
                throw ActionRefused::because('credentials_rejected', 'Could not connect to '.$provider->label().' — check the credentials and admin consent.', 'credentials');
            }
        }

        $this->directories->replaceCredentials($directory, $credentials);

        $this->audit->record(EnterpriseAudit::DIRECTORY_CREDENTIALS_REPLACED, $context->actor(), $directory->organization_id, 'directory', $directory->id, [
            'name' => $directory->name,
            'provider' => $provider->value,
        ]);

        return ActionResult::item($directory, DirectoryFields::present($directory));
    }
}
