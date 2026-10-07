<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Http\Resources\Environment\DomainEventResource;
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
use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\Kernel\Events\Models\Event;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * This environment's domain events — the outbox webhooks are fanned out from — read with a
 * cursor instead of pushed.
 *
 * An agent reconciling state, or a backend that cannot take inbound webhooks, polls this
 * with `after` set to the last id it saw. The same facts a webhook carries, in the order
 * they were written ({@see EventBus::emit()} stamps a ULID per row).
 *
 * THE OUTBOX ROW IS NOT ENVIRONMENT-SCOPED, deliberately — the relay flushes across
 * environments — so nothing on the model narrows this query, and this action does it
 * itself: the environment the request runs in, compared on every query, and no rows at all
 * when there is none. A platform-level event (no environment) is never listed. An
 * organization's administrator would see their organization's events alone.
 */
#[AsAction(
    name: 'events.list',
    summary: 'Read this environment\'s domain events (the facts webhooks deliver) oldest first; pass the last id as `after` to poll for new ones.',
    scope: 'events:read',
    danger: Danger::Read,
    schema: 'DomainEvent',
    tag: 'Events',
    rest: ['GET', '/events'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class ListEvents implements Action
{
    use Paginates;

    public function __construct(private EnvironmentContext $environments) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            ...self::pageFields(),
            Field::list('types', Field::string('type')->max(190))->max(50)->describe('Only these event types, e.g. `user.created`.'),
            Field::string('organization_id')->max(64)->describe('Only this organization\'s events.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $environment = $this->environments->current()?->environmentKey();
        $confinedTo = IntegrationReach::confinedTo($context->principal);
        $organizationId = $confinedTo ?? $context->nullableString('organization_id');
        /** @var list<string> $types */
        $types = array_values(array_filter($context->array('types'), is_string(...)));

        $query = Event::query()
            ->when(
                $environment === null,
                static fn (Builder $q): Builder => $q->whereRaw('1 = 0'),
                static fn (Builder $q): Builder => $q->where('environment_id', $environment),
            )
            ->when($organizationId !== null, static fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->when($types !== [], static fn (Builder $q): Builder => $q->whereIn('type', $types));

        return $this->page($query, $context, DomainEventResource::from(...));
    }
}
