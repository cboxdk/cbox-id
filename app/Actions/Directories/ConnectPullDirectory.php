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
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\DirectoryConnectors;
use Cbox\Id\Directory\DirectoryPullSync;
use Cbox\Id\Directory\Enums\DirectoryProvider;
use Throwable;

/**
 * Connect a directory this platform PULLS people from — Google Workspace or Microsoft
 * Entra: verify the credentials against the provider now, register the directory with
 * them sealed, and run the first sync.
 *
 * THE VERIFY STEP IS THE POINT. Storing credentials nobody has used means the first anyone
 * hears of a wrong key is a nightly sync that quietly provisions nobody. Critical, because
 * it opens a standing sync that creates and deactivates the organization's people with the
 * customer's own provider credentials, which are input only and never returned.
 *
 * A first sync that fails does not undo the connection: its error is recorded on the
 * directory (`last_sync_error`) and shows in the answer.
 */
#[AsAction(
    name: 'directories.connect',
    summary: 'Connect a Google Workspace or Microsoft Entra directory to sync an organization\'s people from, verifying the credentials first. Runs the first sync.',
    scope: 'directory_sync:write',
    danger: Danger::Critical,
    schema: 'Directory',
    tag: 'Directory Sync',
    rest: ['POST', '/directories/connect'],
    status: 201,
    consoleRoutes: ['directories.connect', 'environment.directories.connect'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class ConnectPullDirectory implements Action
{
    public function __construct(
        private Directories $directories,
        private DirectoryConnectors $connectors,
        private DirectoryPullSync $sync,
        private EnterpriseAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->required()->max(64)->describe('The organization whose people it provisions.'),
            Field::string('provider')->required()->oneOf([DirectoryProvider::GoogleWorkspace->value, DirectoryProvider::MicrosoftEntra->value])->describe('google_workspace or microsoft_entra.'),
            Field::object('credentials', [
                Field::string('service_account_json')->max(20000)->describe('Google: the service account\'s JSON key, whole.'),
                Field::string('admin_email')->max(190)->describe('Google: the administrator the service account acts as.'),
                Field::string('tenant_id')->max(190)->describe('Entra: the directory (tenant) id.'),
                Field::string('client_id')->max(190)->describe('Entra: the app registration\'s client id.'),
                Field::string('client_secret')->max(500)->describe('Entra: the app registration\'s client secret.'),
            ])->required()->describe('The provider\'s credentials. Write-only: sealed, never returned.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = EnterpriseReach::requiredOrganization($context);

        EnterpriseReach::assertEntitled($organizationId, 'scim');

        $provider = DirectoryProvider::from($context->string('provider'));

        if (! $provider->isPull() || ! $this->connectors->has($provider)) {
            throw ActionRefused::because('unknown_provider', 'Choose a directory provider.', 'provider');
        }

        $credentials = self::credentials($provider, $context->array('credentials'));

        if (! $this->connectors->for($provider)->verify($credentials)) {
            throw ActionRefused::because('credentials_rejected', 'Could not connect to '.$provider->label().' — check the credentials and admin consent.', 'credentials');
        }

        $directory = $this->directories->registerPull($organizationId, $provider->label(), $provider, $credentials);

        $this->audit->record(EnterpriseAudit::DIRECTORY_CONNECTED, $context->actor(), $organizationId, 'directory', $directory->id, [
            'name' => $directory->name,
            'provider' => $provider->value,
        ]);

        try {
            $this->sync->sync($directory);
        } catch (Throwable) {
            // Already stored on `last_sync_error` by the sync. The connection succeeded
            // and is not rolled back because one fetch did not.
        }

        $directory->refresh();

        return ActionResult::item($directory, DirectoryFields::present($directory));
    }

    /**
     * The credentials the provider's connector reads, or a refusal saying which are missing.
     *
     * @param  array<mixed>  $given
     * @return array<string, string>
     *
     * @throws ActionRefused
     */
    private static function credentials(DirectoryProvider $provider, array $given): array
    {
        $value = static fn (string $key): string => is_string($given[$key] ?? null) ? trim($given[$key]) : '';

        if ($provider === DirectoryProvider::GoogleWorkspace) {
            $serviceAccount = json_decode($value('service_account_json'), true);

            if ($value('admin_email') === '') {
                throw ActionRefused::because('incomplete_credentials', 'Enter the admin email to impersonate.', 'credentials');
            }

            if (! is_array($serviceAccount) || ! is_string($serviceAccount['client_email'] ?? null) || ! is_string($serviceAccount['private_key'] ?? null)) {
                throw ActionRefused::because('incomplete_credentials', 'Paste the full service-account JSON key (it must contain client_email and private_key).', 'credentials');
            }

            return [
                'client_email' => $serviceAccount['client_email'],
                'private_key' => $serviceAccount['private_key'],
                'admin_email' => $value('admin_email'),
            ];
        }

        foreach (['tenant_id', 'client_id', 'client_secret'] as $key) {
            if ($value($key) === '') {
                throw ActionRefused::because('incomplete_credentials', 'Enter the Entra tenant ID, client ID, and client secret.', 'credentials');
            }
        }

        return [
            'tenant_id' => $value('tenant_id'),
            'client_id' => $value('client_id'),
            'client_secret' => $value('client_secret'),
        ];
    }
}
