<?php

declare(strict_types=1);

namespace App\Actions\Radar;

use App\Models\Radar\RadarRule;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Radar\RadarPresenter;
use App\Platform\Radar\RadarRules;

/** This environment's own Radar rules, in the order they are evaluated. */
#[AsAction(
    name: 'radar.rules.list',
    summary: 'List this environment\'s own Radar rules in evaluation order (the first whose conditions all hold decides), with their conditions and a readable summary of each.',
    scope: 'radar:read',
    danger: Danger::Read,
    schema: 'RadarRule',
    tag: 'Radar',
    rest: ['GET', '/radar/rules'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ListRadarRules implements Action
{
    public function __construct(private RadarRules $rules) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::integer('limit')->describe('Rules per page, 1–100. Default 100 — an environment holds at most 100, so one page is all of them.'),
            Field::string('after')->max(26)->describe('The `next_cursor` of the previous page.'),
        ]);
    }

    /**
     * Paged in EVALUATION order, not by id: the order is the point of the list. The cursor
     * is the last rule's id; the next page is the rules after its position.
     */
    public function handle(ActionContext $context): ActionResult
    {
        $asked = $context->input['limit'] ?? RadarRule::MAX_RULES;
        $limit = min(100, max(1, is_numeric($asked) ? (int) $asked : RadarRule::MAX_RULES));
        $rules = array_values($this->rules->all()->all());
        $after = $context->nullableString('after');

        if ($after !== null) {
            $index = array_search($after, array_map(static fn (RadarRule $rule): string => $rule->id, $rules), true);
            $rules = $index === false ? [] : array_slice($rules, $index + 1);
        }

        $visible = array_slice($rules, 0, $limit);
        $hasMore = count($rules) > $limit;
        $last = $visible === [] ? null : $visible[count($visible) - 1];

        return ActionResult::page($visible, array_map(static fn (RadarRule $rule): array => RadarPresenter::rule($rule), $visible), [
            'limit' => $limit,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && $last !== null ? $last->id : null,
        ]);
    }
}
