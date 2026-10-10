<?php

declare(strict_types=1);

namespace App\Actions\Radar;

use App\Models\Radar\RadarListEntry;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use App\Platform\Radar\Enums\RadarList;
use App\Platform\Radar\Enums\RadarListKind;
use App\Platform\Radar\RadarPresenter;

/** This environment's allow and deny lists. */
#[AsAction(
    name: 'radar.lists.list',
    summary: 'List this environment\'s Radar allow and deny entries — IPs and CIDR ranges, addresses, mail domains, devices — optionally one list or one kind.',
    scope: 'radar:read',
    danger: Danger::Read,
    schema: 'RadarListEntry',
    tag: 'Radar',
    rest: ['GET', '/radar/lists'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final class ListRadarListEntries implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('list')->oneOf(RadarList::values())->describe('`allow` or `deny`; both when omitted.'),
            Field::string('kind')->oneOf(RadarListKind::values())->describe('Only entries of this kind.'),
            ...self::pageFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $list = RadarList::tryFrom($context->string('list'));
        $kind = RadarListKind::tryFrom($context->string('kind'));

        $query = RadarListEntry::query()
            ->when($list !== null, static fn ($query) => $query->where('list', $list?->value))
            ->when($kind !== null, static fn ($query) => $query->where('kind', $kind?->value));

        return $this->page($query, $context, static fn (RadarListEntry $entry): array => RadarPresenter::entry($entry));
    }
}
