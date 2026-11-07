<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Radar\AddRadarListEntry;
use App\Actions\Radar\CreateRadarRule;
use App\Actions\Radar\DeleteRadarRule;
use App\Actions\Radar\RemoveRadarListEntry;
use App\Actions\Radar\ReorderRadarRules;
use App\Actions\Radar\SetRadarMode;
use App\Actions\Radar\UpdateRadarRule;
use App\Actions\Radar\UpdateRadarSettings;
use App\Http\Props\Shared\HelpProps;
use App\Models\Radar\RadarListEntry;
use App\Models\Radar\RadarRule;
use App\Models\RiskDecision;
use App\Platform\Help\HelpTopic;
use App\Platform\Locale\ConsoleStrings;
use App\Platform\Radar\Enums\RadarAction;
use App\Platform\Radar\Enums\RadarBuiltin;
use App\Platform\Radar\Enums\RadarField;
use App\Platform\Radar\Enums\RadarList;
use App\Platform\Radar\Enums\RadarListKind;
use App\Platform\Radar\Enums\RadarOperator;
use App\Platform\Radar\Enums\RadarRuleScope;
use App\Platform\Radar\RadarDecisions;
use App\Platform\Radar\RadarLists;
use App\Platform\Radar\RadarPolicy;
use App\Platform\Radar\RadarPresenter;
use App\Platform\Radar\RadarRules;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Response;
use Throwable;

/**
 * CONSOLE › AUTHENTICATION › RADAR — adaptive protection at sign-in and sign-up.
 *
 * Three pages, one environment's:
 *
 *  - the DECISIONS explorer (`environment.radar`): every attempt Radar judged, newest first,
 *    with the verdict, the rule that decided it, every rule that fired and why — the page to
 *    read before switching to enforce, and after a person says "I can't sign in";
 *  - RULES (`environment.radar.rules`): the mode, the built-in rules and their tuning, and the
 *    environment's own rules in order;
 *  - LISTS (`environment.radar.lists`): what is always allowed and always refused.
 *
 * Every write is an action (`App\Actions\Radar\*`) — the same the management API and MCP run,
 * with the same trail line. The mode switch sits behind `env.sudo`: turning enforcement on or
 * off is the environment's security posture.
 */
final readonly class RadarController extends ConsoleController
{
    private const int PER_PAGE = 50;

    /** Action input name => this page's form field. */
    private const array RULE_FIELDS = [
        'name' => 'name',
        'description' => 'description',
        'action' => 'action',
        'applies_to' => 'appliesTo',
        'conditions' => 'conditions',
        'enabled' => 'enabled',
        'position' => 'position',
    ];

    public function index(Request $request, RadarDecisions $decisions, RadarPolicy $policy): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        $filters = [
            'verdict' => self::oneOf($request->string('verdict')->toString(), RadarAction::values()),
            'flow' => self::oneOf($request->string('flow')->toString(), ['sign_in', 'sign_up']),
            'rule' => self::text($request, 'rule', 64),
            'country' => self::text($request, 'country', 2),
            'email' => self::text($request, 'email', 255),
            'ip' => self::text($request, 'ip', 64),
            'device' => self::text($request, 'device', 64),
            'from' => self::day($request->string('from')->toString(), false),
            'to' => self::day($request->string('to')->toString(), true),
        ];

        $after = self::text($request, 'after', 26);
        $page = $decisions->page($filters, self::PER_PAGE, $after);
        $names = $decisions->ruleNames($page['rows']);

        return $this->page('console/radar/index', __('console.radar_524f8327'), [
            'help' => HelpProps::for(HelpTopic::Radar),
            'tabs' => $this->tabs('decisions'),
            'mode' => $this->modeProps($policy),
            'summary' => $this->summary(),
            'decisions' => array_map(static fn (RiskDecision $row): array => RadarPresenter::decision($row, $names), $page['rows']),
            'filters' => [
                'verdict' => $request->string('verdict')->toString(),
                'flow' => $request->string('flow')->toString(),
                'rule' => $request->string('rule')->toString(),
                'country' => $request->string('country')->toString(),
                'email' => $request->string('email')->toString(),
                'ip' => $request->string('ip')->toString(),
                'device' => $request->string('device')->toString(),
                'from' => $request->string('from')->toString(),
                'to' => $request->string('to')->toString(),
            ],
            'ruleOptions' => $this->ruleOptions(),
            'nextHref' => $page['next_cursor'] === null ? null : $request->fullUrlWithQuery(['after' => $page['next_cursor']]),
            'firstHref' => $after === null ? null : $request->fullUrlWithQuery(['after' => null]),
            'listStoreHref' => route('environment.radar.lists.store'),
        ]);
    }

    public function rules(RadarRules $rules, RadarPolicy $policy): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        return $this->page('console/radar/rules', __('console.radar_524f8327'), [
            'help' => HelpProps::for(HelpTopic::Radar),
            'tabs' => $this->tabs('rules'),
            'mode' => $this->modeProps($policy),
            'settings' => $this->translatedSettings($policy),
            'settingsHref' => route('environment.radar.settings.update'),
            'rules' => array_values($rules->all()->map(static fn (RadarRule $rule): array => [
                ...RadarPresenter::rule($rule),
                'href' => route('environment.radar.rules.edit', $rule->id),
                'destroyHref' => route('environment.radar.rules.destroy', $rule->id),
            ])->all()),
            'createHref' => route('environment.radar.rules.create'),
            'orderHref' => route('environment.radar.rules.order'),
        ]);
    }

    public function create(): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        return $this->editor(null);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $result = $this->act(CreateRadarRule::class, $this->ruleInput($request, creating: true), self::RULE_FIELDS, 'conditions');

        return $result instanceof RedirectResponse
            ? $result
            : to_route('environment.radar.rules')->with('status', __('console.rule_added_dadb1f36'));
    }

    public function edit(RadarRules $rules, string $rule): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        return $this->editor($rules->find($rule) ?? abort(404));
    }

    public function update(Request $request, string $rule): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $result = $this->act(UpdateRadarRule::class, ['id' => $rule, ...$this->ruleInput($request, creating: false)], self::RULE_FIELDS, 'conditions');

        return $result instanceof RedirectResponse
            ? $result
            : to_route('environment.radar.rules')->with('status', __('console.rule_saved_a456e565'));
    }

    public function destroy(string $rule): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $result = $this->act(DeleteRadarRule::class, ['id' => $rule]);

        return $result instanceof RedirectResponse
            ? $result
            : to_route('environment.radar.rules')->with('status', __('console.rule_deleted_d7ab3270'));
    }

    public function order(Request $request): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $ids = $request->input('ruleIds');

        $result = $this->act(ReorderRadarRules::class, [
            'rule_ids' => is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [],
        ], ['rule_ids' => 'ruleIds'], 'ruleIds');

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.order_saved_779c10e8'));
    }

    public function settings(Request $request): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $posted = $request->input('builtinRules');
        $builtins = [];

        foreach (is_array($posted) ? $posted : [] as $key => $setting) {
            if (! is_string($key) || ! is_array($setting) || RadarBuiltin::tryFrom($key) === null) {
                continue;
            }

            $builtins[$key] = array_filter([
                'enabled' => array_key_exists('enabled', $setting) ? filter_var($setting['enabled'], FILTER_VALIDATE_BOOLEAN) : null,
                'action' => is_string($setting['action'] ?? null) ? $setting['action'] : null,
                'threshold' => is_numeric($setting['threshold'] ?? null) ? (int) $setting['threshold'] : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $result = $this->act(UpdateRadarSettings::class, ['builtin_rules' => $builtins], ['builtin_rules' => 'builtinRules'], 'builtinRules');

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.built_in_rules_saved_671a0b68'));
    }

    public function mode(Request $request): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $result = $this->act(SetRadarMode::class, ['mode' => $request->string('mode')->toString()], ['mode' => 'mode'], 'mode');

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', $request->string('mode')->toString() === 'enforce' ? __('console.radar_now_enforces_blocks_refuse_challenges_ask_for_a_s_fe2c0317') : __('console.radar_now_monitors_every_verdict_is_recorded_none_is_ac_6ca10a23'));
    }

    public function lists(RadarLists $lists): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        return $this->page('console/radar/lists', __('console.radar_524f8327'), [
            'help' => HelpProps::for(HelpTopic::Radar),
            'tabs' => $this->tabs('lists'),
            'entries' => array_values($lists->all()->map(static fn (RadarListEntry $entry): array => [
                ...RadarPresenter::entry($entry),
                'destroyHref' => route('environment.radar.lists.destroy', $entry->id),
            ])->all()),
            'kinds' => array_map(static fn (RadarListKind $kind): array => ['value' => $kind->value, 'label' => match ($kind) {
                RadarListKind::Ip => __('console.ip_address_or_range_b5bbac17'),
                RadarListKind::Email => __('console.email_address_f2488fd4'),
                RadarListKind::EmailDomain => __('console.email_domain_f865f1ee'),
                RadarListKind::Device => __('console.device_6ba0bdec'),
            }], RadarListKind::cases()),
            'storeHref' => route('environment.radar.lists.store'),
        ]);
    }

    public function addEntry(Request $request): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $expires = $request->string('expiresAt')->toString();

        $result = $this->act(AddRadarListEntry::class, array_filter([
            'list' => $request->string('list')->toString(),
            'kind' => $request->string('kind')->toString(),
            'value' => $request->string('value')->toString(),
            'note' => $request->string('note')->toString(),
            'expires_at' => $expires === '' ? null : (self::day($expires, true)?->toIso8601String() ?? $expires),
        ], static fn (mixed $value): bool => $value !== null && $value !== ''), [
            'list' => 'list',
            'kind' => 'kind',
            'value' => 'value',
            'note' => 'note',
            'expires_at' => 'expiresAt',
        ], 'value');

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.added_to_the_list_760cb12c'));
    }

    public function removeEntry(string $entry): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $result = $this->act(RemoveRadarListEntry::class, ['id' => $entry]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.removed_from_the_list_642fa915'));
    }

    /** @return array<string, mixed> */
    private function translatedSettings(RadarPolicy $policy): array
    {
        $settings = RadarPresenter::settings($policy);
        /** @var list<array{key: string, name: string, description: string}> $builtins */
        $builtins = $settings['builtin_rules'];
        foreach ($builtins as &$builtin) {
            $rule = RadarBuiltin::from($builtin['key']);
            $builtin['name'] = $rule->label();
            $builtin['description'] = $rule->description();
        }
        $settings['builtin_rules'] = $builtins;

        return $settings;
    }

    private function editor(?RadarRule $rule): Response
    {
        // Titled by the rule, like every other detail page: "Radar" over a rule's editor
        // said which area it was in — the breadcrumb says that — and not which rule.
        return $this->page('console/radar/rule', $rule === null ? __('console.new_rule_99cd24ef') : $rule->name, [
            'help' => HelpProps::for(HelpTopic::Radar),
            'rule' => $rule === null ? null : RadarPresenter::rule($rule),
            'fields' => array_map(static fn (RadarField $field): array => [
                'value' => $field->value,
                'label' => $field->label(),
                'type' => $field->type(),
                'operators' => array_map(static fn (RadarOperator $operator): array => [
                    'value' => $operator->value,
                    'label' => ConsoleStrings::text($operator->phrase()),
                    'list' => $operator->takesList(),
                ], $field->operators()),
            ], RadarField::cases()),
            'actions' => RadarAction::values(),
            'scopes' => RadarRuleScope::values(),
            'submitHref' => $rule === null ? route('environment.radar.rules.store') : route('environment.radar.rules.update', $rule->id),
            'destroyHref' => $rule === null ? null : route('environment.radar.rules.destroy', $rule->id),
            'rulesHref' => route('environment.radar.rules'),
        ]);
    }

    /**
     * The editor posts conditions as `{field, operator, value}` with `value` a string; a list
     * operator's value is comma-separated in the form and a list for the action.
     *
     * @return array<string, mixed>
     */
    private function ruleInput(Request $request, bool $creating): array
    {
        $conditions = [];

        foreach ((array) $request->input('conditions', []) as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $operator = RadarOperator::tryFrom(is_string($condition['operator'] ?? null) ? $condition['operator'] : '');
            $value = is_scalar($condition['value'] ?? null) ? (string) $condition['value'] : '';

            $conditions[] = array_filter([
                'field' => is_string($condition['field'] ?? null) ? $condition['field'] : '',
                'operator' => $operator->value ?? (is_string($condition['operator'] ?? null) ? $condition['operator'] : ''),
                'value' => $operator?->takesList() === true ? null : $value,
                'values' => $operator?->takesList() === true
                    ? array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $one): bool => $one !== ''))
                    : null,
            ], static fn (mixed $part): bool => $part !== null);
        }

        $input = [
            'name' => $request->string('name')->toString(),
            'description' => $request->string('description')->toString(),
            'action' => $request->string('action')->toString(),
            'applies_to' => $request->string('appliesTo')->toString() ?: 'all',
            'conditions' => $conditions,
            'enabled' => $request->boolean('enabled', true),
        ];

        if ($request->filled('position') && is_numeric($request->input('position'))) {
            $input['position'] = (int) $request->input('position');
        }

        return $creating ? $input : array_filter($input, static fn (mixed $value): bool => $value !== '');
    }

    /**
     * @return array{mode: string, inherited: bool, deploymentMode: string, intelligence: string, href: string}
     */
    private function modeProps(RadarPolicy $policy): array
    {
        return [
            'mode' => $policy->mode()->value,
            'inherited' => $policy->modeInherited(),
            'deploymentMode' => RadarPolicy::deploymentMode()->value,
            'intelligence' => RadarPresenter::intelligenceDriver(),
            'href' => route('environment.radar.mode.update'),
        ];
    }

    /**
     * The last 24 hours by verdict — the three numbers a person reads before anything else.
     *
     * @return array{allow: int, challenge: int, block: int}
     */
    private function summary(): array
    {
        $environment = app(EnvironmentContext::class)->current()?->environmentKey();
        $counts = ['allow' => 0, 'challenge' => 0, 'block' => 0];

        if ($environment === null) {
            return $counts;
        }

        $rows = RiskDecision::query()
            ->where('environment_id', $environment)
            ->where('assessed_at', '>=', Carbon::now()->subDay())
            ->whereNotNull('verdict')
            ->selectRaw('verdict, count(*) as aggregate')
            ->groupBy('verdict')
            ->get();

        foreach ($rows as $row) {
            $verdict = $row->getAttribute('verdict');

            if (is_string($verdict) && array_key_exists($verdict, $counts)) {
                $aggregate = $row->getAttribute('aggregate');
                $counts[$verdict] = is_numeric($aggregate) ? (int) $aggregate : 0;
            }
        }

        return $counts;
    }

    /**
     * The rule keys the explorer can filter by.
     *
     * @return list<array{value: string, label: string}>
     */
    private function ruleOptions(): array
    {
        $options = [];

        foreach (RadarBuiltin::cases() as $builtin) {
            $options[] = ['value' => 'builtin:'.$builtin->value, 'label' => $builtin->label()];
        }

        foreach (RadarRule::query()->orderBy('position')->get(['id', 'name']) as $rule) {
            $options[] = ['value' => 'rule:'.$rule->id, 'label' => $rule->name];
        }

        foreach (RadarList::cases() as $list) {
            foreach (RadarListKind::cases() as $kind) {
                $options[] = ['value' => $list->value.'_list:'.$kind->value, 'label' => __(match ($list->value.':'.$kind->value) {
                    'allow:ip' => 'console.radar_allow_ip',
                    'allow:email' => 'console.radar_allow_email',
                    'allow:email_domain' => 'console.radar_allow_email_domain',
                    'allow:device' => 'console.radar_allow_device',
                    'deny:ip' => 'console.radar_deny_ip',
                    'deny:email' => 'console.radar_deny_email',
                    'deny:email_domain' => 'console.radar_deny_email_domain',
                    'deny:device' => 'console.radar_deny_device',
                })];
            }
        }

        return $options;
    }

    /**
     * @return list<array{key: string, label: string, href: string, current: bool}>
     */
    private function tabs(string $current): array
    {
        return [
            ['key' => 'decisions', 'label' => __('console.decisions_cfa6a08a'), 'href' => route('environment.radar'), 'current' => $current === 'decisions'],
            ['key' => 'rules', 'label' => __('console.rules_4228aeb0'), 'href' => route('environment.radar.rules'), 'current' => $current === 'rules'],
            ['key' => 'lists', 'label' => __('console.allow_deny_lists_83613d84'), 'href' => route('environment.radar.lists'), 'current' => $current === 'lists'],
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function oneOf(string $value, array $allowed): ?string
    {
        return in_array($value, $allowed, true) ? $value : null;
    }

    private static function text(Request $request, string $key, int $max): ?string
    {
        $value = trim($request->string($key)->toString());

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function day(string $day, bool $end): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $day, 'UTC');
        } catch (Throwable) {
            return null;
        }

        if (! $date instanceof Carbon) {
            return null;
        }

        return $end ? $date->endOfDay() : $date->startOfDay();
    }
}
