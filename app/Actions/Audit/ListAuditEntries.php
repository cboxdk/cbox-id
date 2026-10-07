<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Http\Resources\Environment\AuditEntryResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use App\Platform\Integrations\IntegrationReach;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * This environment's audit trail, read with a cursor — what the console's Audit page
 * shows, for an agent answering "who changed that?" or a backend tailing its own trail.
 *
 * Bounded by the entry's own environment scope: {@see AuditEntry} is environment-owned, so
 * another environment's chain — and the management plane's, which lives in the platform
 * root — is not in the query at all. An organization's administrator would see their
 * organization's entries alone. The chain's hashes are not returned; verifying the chain
 * is the operator's tool, not a page of JSON.
 */
#[AsAction(
    name: 'audit.list',
    summary: 'Read this environment\'s audit trail oldest first, optionally narrowed by action, actor type or organization; pass the last id as `after` to page.',
    scope: 'audit:read',
    danger: Danger::Read,
    schema: 'AuditEntry',
    tag: 'Audit log',
    rest: ['GET', '/audit-log'],
    consoleGate: ConsoleGate::Administer,
)]
final class ListAuditEntries implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            ...self::pageFields(),
            Field::string('action')->max(190)->describe('Only this action, e.g. `webhook.created`.'),
            Field::string('actor_type')->oneOf(array_map(static fn (ActorType $type): string => $type->value, ActorType::cases()))->describe('Only entries by this kind of actor; `service` is a management key or an app.'),
            Field::string('organization_id')->max(64)->describe('Only this organization\'s entries.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = IntegrationReach::confinedTo($context->principal) ?? $context->nullableString('organization_id');
        $action = $context->nullableString('action');
        $actorType = $context->nullableString('actor_type');

        $query = AuditEntry::query()
            ->when($organizationId !== null, static fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->when($action !== null, static fn (Builder $q): Builder => $q->where('action', $action))
            ->when($actorType !== null, static fn (Builder $q): Builder => $q->where('actor_type', $actorType));

        return $this->page($query, $context, AuditEntryResource::from(...));
    }
}
