<?php

declare(strict_types=1);

namespace App\Actions\Radar;

use App\Models\RiskDecision;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Radar\Enums\RadarAction;
use App\Platform\Radar\Enums\RadarFlow;
use App\Platform\Radar\RadarDecisions;
use App\Platform\Radar\RadarPresenter;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * THE DECISIONS EXPLORER, over the API: why a sign-in or a sign-up was allowed, challenged or
 * blocked — the deciding rule, every rule that fired, the reasons and the facts.
 */
#[AsAction(
    name: 'radar.decisions.list',
    summary: 'List this environment\'s Radar decisions newest first — verdict, deciding rule, every rule that fired, reasons and facts — filtered by verdict, flow, rule, country, email, IP, device or time; pass `next_cursor` as `after` to page. Email and IP are matched by keyed pseudonym and never returned.',
    scope: 'radar:read',
    danger: Danger::Read,
    schema: 'RadarDecision',
    tag: 'Radar',
    rest: ['GET', '/radar/decisions'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ListRadarDecisions implements Action
{
    public function __construct(private RadarDecisions $decisions) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('verdict')->oneOf(RadarAction::values())->describe('`allow`, `challenge` or `block`.'),
            Field::string('flow')->oneOf([RadarFlow::SignIn->value, RadarFlow::SignUp->value])->describe('`sign_in` or `sign_up`.'),
            Field::string('rule')->max(64)->describe('A rule key — `builtin:credential_stuffing`, `rule:<id>`, `deny_list:ip` — that decided or fired.'),
            Field::string('country')->max(2)->describe('Two-letter country code.'),
            Field::string('email')->max(255)->describe('Attempts with this address (matched by pseudonym).'),
            Field::string('ip')->max(64)->describe('Attempts from this IP (matched by pseudonym).'),
            Field::string('device')->max(64)->describe('Attempts from this device id.'),
            Field::boolean('enforced')->describe('Only decisions that were (true) or were not (false) acted on.'),
            Field::string('from')->format('date-time')->describe('On or after this time (ISO 8601).'),
            Field::string('to')->format('date-time')->describe('On or before this time (ISO 8601).'),
            Field::integer('limit')->min(1)->max(100)->describe('Decisions per page, 1–100. Default 50.'),
            Field::string('after')->max(26)->describe('The `next_cursor` of the previous page: the decisions older than it.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $asked = $context->input['limit'] ?? 50;
        $limit = min(100, max(1, is_numeric($asked) ? (int) $asked : 50));

        $page = $this->decisions->page([
            'verdict' => $context->nullableString('verdict'),
            'flow' => $context->nullableString('flow'),
            'rule' => $context->nullableString('rule'),
            'country' => $context->nullableString('country'),
            'email' => $context->nullableString('email'),
            'ip' => $context->nullableString('ip'),
            'device' => $context->nullableString('device'),
            'enforced' => $context->has('enforced') ? $context->boolean('enforced') : null,
            'from' => self::time($context, 'from'),
            'to' => self::time($context, 'to'),
        ], $limit, $context->nullableString('after'));

        $names = $this->decisions->ruleNames($page['rows']);

        return ActionResult::page(
            $page['rows'],
            array_map(static fn (RiskDecision $row): array => RadarPresenter::decision($row, $names), $page['rows']),
            ['limit' => $limit, 'has_more' => $page['has_more'], 'next_cursor' => $page['next_cursor']],
        );
    }

    private static function time(ActionContext $context, string $field): ?Carbon
    {
        $value = $context->nullableString($field);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            throw ActionRefused::because('invalid_filter', "{$field} must be an ISO 8601 date and time.", $field);
        }
    }
}
