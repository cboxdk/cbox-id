<?php

declare(strict_types=1);

namespace App\Platform\Console;

use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Http\Request;

/**
 * The activity-log entries for an app's lifecycle as the CONSOLE drives it: registered,
 * edited, secret rotated, deleted.
 *
 * None of the four was recorded. Registering an app mints a credential that signs in as
 * it; rotating one replaces the credential a production deployment holds; deleting one
 * stops everything using it — and the activity log, the page an administrator goes to
 * when something stops working, said nothing about any of them.
 *
 * ON THE APP'S OWN CHAIN: the owning organization's trail, or the environment's system
 * trail for an app the environment owns. That is the chain the activity page filters by,
 * so a tenant reads its own apps' history and nobody else's.
 *
 * HERE, NOT IN THE FRAMEWORK, FOR NOW — and written to be removed. The framework's client
 * service is gaining lifecycle audit of its own. When a release carrying it is required,
 * these four entries become duplicates of what it writes; every one carries
 * `context.recorded_by = console` so the two can be told apart in an existing trail, and
 * this class is the one place to delete. The action names follow the framework's
 * `<noun>.<past-tense verb>` shape (`service_account.rotated`) so a reader filtering on
 * `client.` finds both.
 */
final readonly class ClientLifecycleAudit
{
    public const string CREATED = 'client.created';

    public const string UPDATED = 'client.updated';

    public const string SECRET_ROTATED = 'client.secret_rotated';

    public const string DELETED = 'client.deleted';

    public function __construct(
        private AuditLog $audit,
        private ConsoleScope $scope,
    ) {}

    public function created(Client $client, Request $request): void
    {
        $this->record(self::CREATED, $client, $request, [
            'name' => $client->name,
            'type' => $client->type->value,
            'first_party' => $client->first_party,
            'grant_types' => array_values($client->grant_types),
        ]);
    }

    /**
     * @param  list<string>  $changed  the attributes the edit actually changed
     */
    public function updated(Client $client, array $changed, Request $request): void
    {
        $this->record(self::UPDATED, $client, $request, ['name' => $client->name, 'changed' => $changed]);
    }

    public function secretRotated(Client $client, Request $request): void
    {
        $this->record(self::SECRET_ROTATED, $client, $request, ['name' => $client->name]);
    }

    public function deleted(Client $client, Request $request): void
    {
        $this->record(self::DELETED, $client, $request, ['name' => $client->name]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $action, Client $client, Request $request, array $context): void
    {
        $actorId = $this->scope->actorId();

        $this->audit->record(new AuditEvent(
            action: $action,
            /*
             * WHO, by the plane they acted from: on the organization plane a tenant's own
             * administrator, who is a user of this environment; on the environment plane
             * one of the customer's people, acting above every organization in it. The
             * difference is what an auditor needs to resolve the id against.
             */
            actorType: $this->scope->plane() === ConsolePlane::Organization
                ? ActorType::User
                : ActorType::OrganizationMember,
            actorId: $actorId === '' ? null : $actorId,
            organizationId: $client->organization_id,
            // The public client_id, not the row key: it is what the activity page resolves
            // to the app's name, and what the reader will search their own logs for.
            targetType: 'client',
            targetId: $client->client_id,
            context: [...$context, 'recorded_by' => 'console'],
            ip: $request->ip(),
        ));
    }
}
