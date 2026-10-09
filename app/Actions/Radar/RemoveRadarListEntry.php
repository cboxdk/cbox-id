<?php

declare(strict_types=1);

namespace App\Actions\Radar;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Radar\RadarLists;
use App\Platform\Radar\RadarTrail;

/** Take an entry off the allow or deny list. */
#[AsAction(
    name: 'radar.lists.remove',
    summary: 'Remove an entry from this environment\'s Radar allow or deny list.',
    scope: 'radar:write',
    danger: Danger::Destructive,
    tag: 'Radar',
    rest: ['DELETE', '/radar/lists/{id}'],
    status: 204,
    consoleRoutes: ['environment.radar.lists.destroy'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class RemoveRadarListEntry implements Action
{
    public function __construct(
        private RadarLists $lists,
        private RadarTrail $trail,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(26)->describe('The entry\'s id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $entry = $this->lists->find($context->string('id')) ?? throw ActionRefused::notFound('radar list entry');

        $this->lists->remove($entry);

        $this->trail->record(RadarTrail::LIST_ENTRY_REMOVED, 'radar_list_entry', $entry->id, $context->actor(), [
            'list' => $entry->list->value,
            'kind' => $entry->kind->value,
        ]);

        return ActionResult::none($entry);
    }
}
