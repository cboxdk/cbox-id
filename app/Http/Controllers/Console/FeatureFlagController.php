<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\FeatureFlags\CreateFeatureFlag;
use App\Actions\FeatureFlags\DeleteFeatureFlag;
use App\Actions\FeatureFlags\UpdateFeatureFlag;
use App\Http\Props\Shared\HelpProps;
use App\Platform\Console\Vocabulary;
use App\Platform\Help\HelpTopic;
use Cbox\Id\FeatureFlags\Contracts\FeatureFlags;
use Cbox\Id\FeatureFlags\Enums\EvaluationReason;
use Cbox\Id\FeatureFlags\Models\FeatureFlag;
use Cbox\Id\Identity\Contracts\Subjects;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * DEVELOPERS › FEATURE FLAGS — the switches the apps built on this environment ask about,
 * per user and per organization.
 *
 * THE ENVIRONMENT CONSOLE ONLY. A flag is read by every app and every organization in the
 * environment, so it is the environment's to define; an organization's administrator
 * turning a flag on for their own organization would be turning it on in someone else's
 * product.
 *
 * Every write is an ACTION (`App\Actions\FeatureFlags\*`), the same class the management
 * API's `/v1/feature-flags` and MCP run, so a change is checked, recorded and announced the
 * same way whichever door made it. This controller maps the two forms on the page — the
 * flag's details, and its targeting — onto the action's input, and resolves the one thing
 * a person types that a machine would not: a user rule by email address.
 *
 * The page also answers "is it on for this person?" with the same evaluation the token and
 * the API use, and the rule that decided — the question every flag is eventually asked.
 */
final readonly class FeatureFlagController extends ConsoleController
{
    public function index(FeatureFlags $flags): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        return $this->page('console/feature-flags/index', Vocabulary::FEATURE_FLAGS, [
            'help' => HelpProps::for(HelpTopic::FeatureFlags),
            'flags' => array_map(static function (FeatureFlag $flag): array {
                $targeting = $flag->targeting();

                return [
                    'id' => $flag->id,
                    'key' => $flag->key,
                    'description' => $flag->description,
                    'enabled' => $flag->enabled,
                    'defaultValue' => $flag->default_value,
                    'userRules' => count($targeting->users),
                    'organizationRules' => count($targeting->organizations),
                    'rolloutPercentage' => $targeting->rolloutPercentage,
                    'href' => route('environment.feature-flags.show', $flag->id),
                ];
            }, $flags->all()),
            'createHref' => route('environment.feature-flags.create'),
        ]);
    }

    public function create(): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        return $this->page('console/feature-flags/create', 'New feature flag', [
            'indexHref' => route('environment.feature-flags'),
            'storeHref' => route('environment.feature-flags.store'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $result = $this->act(CreateFeatureFlag::class, [
            'key' => trim($request->string('key')->value()),
            'description' => $request->string('description')->value(),
            'default_value' => $request->boolean('defaultValue'),
        ], ['key' => 'key', 'description' => 'description', 'default_value' => 'defaultValue'], 'key');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var FeatureFlag $created */
        $created = $result->value;

        return to_route('environment.feature-flags.show', $created->id)
            ->with('status', 'Feature flag "'.$created->key.'" created. It is off for everyone until a rule below, or its default, turns it on.');
    }

    public function show(Request $request, string $flag, FeatureFlags $flags, Subjects $subjects): Response
    {
        $model = $this->flag($flag, $flags);
        $targeting = $model->targeting();

        $organizationNames = $this->scope->organizationNames(array_keys($targeting->organizations));
        $people = $subjects->findMany(array_map('strval', array_keys($targeting->users)));

        return $this->page('console/feature-flags/show', $model->key, [
            'help' => HelpProps::for(HelpTopic::FeatureFlags),
            'flag' => [
                'id' => $model->id,
                'key' => $model->key,
                'description' => $model->description ?? '',
                'enabled' => $model->enabled,
                'defaultValue' => $model->default_value,
                'rolloutPercentage' => $targeting->rolloutPercentage,
            ],
            'users' => $this->ruleRows($targeting->users, static fn (string $id): string => $people[$id]->email ?? $people[$id]->name ?? $id),
            'organizations' => $this->ruleRows($targeting->organizations, static fn (string $id): string => $organizationNames[$id] ?? $id),
            'evaluation' => $this->evaluation($request, $model, $flags, $subjects),
            'lookupHref' => route('environment.lookup.organizations'),
            'indexHref' => route('environment.feature-flags'),
            'urls' => [
                'show' => route('environment.feature-flags.show', $model->id),
                'update' => route('environment.feature-flags.update', $model->id),
                'destroy' => route('environment.feature-flags.destroy', $model->id),
            ],
        ]);
    }

    /**
     * One of the page's two forms: the details (description, switch, default), or the
     * targeting (user rules, organization rules, rollout). Each sends only its own fields,
     * so saving one never resets the other.
     */
    public function update(Request $request, string $flag, FeatureFlags $flags, Subjects $subjects): RedirectResponse
    {
        $model = $this->flag($flag, $flags);

        $input = ['id' => $model->id];

        if ($request->has('description')) {
            $input['description'] = $request->string('description')->value();
        }

        foreach (['enabled' => 'enabled', 'defaultValue' => 'default_value'] as $field => $name) {
            if ($request->has($field)) {
                $input[$name] = $request->boolean($field);
            }
        }

        if ($request->has('organizations')) {
            $input['organizations'] = $this->rules($request->input('organizations'), static fn (string $id): string => $id);
        }

        if ($request->has('users')) {
            $unknown = [];
            $input['users'] = $this->rules($request->input('users'), function (string $reference) use ($subjects, &$unknown): string {
                if (! str_contains($reference, '@')) {
                    return $reference;
                }

                $subject = $subjects->findByEmail($reference);

                if ($subject === null) {
                    $unknown[] = $reference;
                }

                return $subject->id ?? $reference;
            });

            if ($unknown !== []) {
                return back()->withInput()->withErrors(['users' => 'Nobody in this environment signs in as '.implode(', ', $unknown).'.']);
            }
        }

        if ($request->has('rolloutPercentage')) {
            $percentage = $request->input('rolloutPercentage');
            $input['rollout_percentage'] = is_numeric($percentage) ? (int) $percentage : null;
        }

        $result = $this->act(UpdateFeatureFlag::class, $input, [
            'description' => 'description',
            'enabled' => 'enabled',
            'default_value' => 'defaultValue',
            'users' => 'users',
            'organizations' => 'organizations',
            'rollout_percentage' => 'rolloutPercentage',
        ], 'form');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Feature flag "'.$model->key.'" saved.');
    }

    public function destroy(string $flag, FeatureFlags $flags): RedirectResponse
    {
        $model = $this->flag($flag, $flags);

        $result = $this->act(DeleteFeatureFlag::class, ['id' => $model->id]);

        return $result instanceof RedirectResponse
            ? $result
            : to_route('environment.feature-flags')->with('status', 'Feature flag "'.$model->key.'" deleted. Apps asking for it now get off.');
    }

    /**
     * The flag, in THIS environment, for an environment administrator — or 404.
     */
    private function flag(string $id, FeatureFlags $flags): FeatureFlag
    {
        $this->scope->assertMayAdministerEnvironment();

        $flag = $flags->find($id);

        abort_if($flag === null, 404);

        return $flag;
    }

    /**
     * The page's "is it on for…?" answer, when the query asks: `?user=` (an email or a user
     * id) and/or `?organization=` (an organization id).
     *
     * @return array{user: string, organization: string, enabled: bool, reason: string, explanation: string}|null
     */
    private function evaluation(Request $request, FeatureFlag $flag, FeatureFlags $flags, Subjects $subjects): ?array
    {
        $user = trim($request->string('user')->value());
        $organization = trim($request->string('organization')->value());

        if ($user === '' && $organization === '') {
            return null;
        }

        $userId = $user === '' ? null : (str_contains($user, '@') ? ($subjects->findByEmail($user)->id ?? $user) : $user);

        $answer = $flags->evaluate($flag->key, $userId, $organization === '' ? null : $organization);

        return [
            'user' => $user,
            'organization' => $organization,
            'enabled' => $answer->enabled,
            'reason' => $answer->reason->value,
            'explanation' => match ($answer->reason) {
                EvaluationReason::Disabled => 'The flag is switched off, so it is off for everyone.',
                EvaluationReason::UserTarget => 'A rule names this user.',
                EvaluationReason::OrganizationTarget => 'A rule names this organization.',
                EvaluationReason::Rollout => 'This user falls inside the rollout percentage.',
                EvaluationReason::Default => 'No rule matched, so the default applies.',
                EvaluationReason::UnknownFlag => 'The flag no longer exists.',
            },
        ];
    }

    /**
     * The page's rows for one kind of rule: the id, what a person reads for it, on or off.
     *
     * @param  array<string, bool>  $map
     * @param  callable(string): string  $label
     * @return list<array{id: string, label: string, enabled: bool}>
     */
    private function ruleRows(array $map, callable $label): array
    {
        $rows = [];

        foreach ($map as $id => $enabled) {
            $rows[] = ['id' => (string) $id, 'label' => $label((string) $id), 'enabled' => $enabled];
        }

        return $rows;
    }

    /**
     * Form rules (`[{id, enabled}]`) as the action's input, each id passed through $resolve.
     *
     * @param  callable(string): string  $resolve
     * @return list<array{id: string, enabled: bool}>
     */
    private function rules(mixed $rules, callable $resolve): array
    {
        $out = [];

        foreach (is_array($rules) ? $rules : [] as $rule) {
            if (! is_array($rule) || ! is_string($rule['id'] ?? null) || trim($rule['id']) === '') {
                continue;
            }

            $out[] = ['id' => $resolve(trim($rule['id'])), 'enabled' => filter_var($rule['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)];
        }

        return $out;
    }
}
