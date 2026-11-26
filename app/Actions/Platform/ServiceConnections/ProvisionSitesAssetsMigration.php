<?php

declare(strict_types=1);

namespace App\Actions\Platform\ServiceConnections;

use App\Actions\Platform\AsOperator;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Preflight;
use App\Platform\Actions\Principal\OperatorPrincipal;
use App\ServiceConnections\SitesAssetsMigration;
use Illuminate\Auth\Access\AuthorizationException;

#[AsAction(
    name: 'admin.service_connections.sites_assets.provision',
    summary: 'Provision only the exact attested Sites-to-Assets migration plan after the operator approves it. Never reactivate revoked connections.',
    scope: 'operator:service-connections:write', danger: Danger::Critical, plane: ActionPlane::Platform,
    rest: ['POST', '/sites-assets-migrations/provision'], consoleGate: ConsoleGate::Operator,
)]
final readonly class ProvisionSitesAssetsMigration implements Action, Preflight
{
    public function __construct(private SitesAssetsMigration $migration) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::object('plan', [
                Field::string('manifest_digest')->required()->max(64),
                Field::string('environment_id')->required(),
                Field::string('consumer_client_id')->required(),
                Field::string('provider_client_id')->required(),
                Field::list('organization_ids', Field::string('id'))->required()->min(1)->max(50),
                Field::string('evidence')->required()->max(300),
                Field::string('expires_at')->required(),
                Field::string('service')->required(),
                Field::string('audience')->required(),
                Field::list('scopes', Field::string('scope'))->required()->max(2),
            ])->required(),
        ]);
    }

    public function preflight(ActionContext $context): void
    {
        // Only the delegated operator door, whose Critical actions require one-use approval.
        // There is deliberately no console shortcut or machine bootstrap credential.
        if (! $context->principal instanceof OperatorPrincipal) {
            throw new AuthorizationException('This migration requires the operator approval flow.');
        }
        AsOperator::id($context->principal);
        $this->migration->assertPlan($context->array('plan'));
    }

    public function handle(ActionContext $context): ActionResult
    {
        $this->preflight($context);
        $connections = $this->migration->provision($context->array('plan'), AsOperator::id($context->principal), $context->principal->approverSubjectId() ?? '');

        // These are service grants, not SSO connections (whose public prefix is conn_).
        return ActionResult::item($connections, ['connections' => array_map(
            static fn (array $connection): array => ['object' => 'service_connection', ...$connection],
            $connections,
        )]);
    }
}
