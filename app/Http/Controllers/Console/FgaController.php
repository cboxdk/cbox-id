<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Fga\CheckFgaRelation;
use App\Actions\Fga\DeleteFgaTuples;
use App\Actions\Fga\ListFgaTuples;
use App\Actions\Fga\ShowFgaSchema;
use App\Actions\Fga\UpdateFgaSchema;
use App\Actions\Fga\ValidateFgaSchema;
use App\Actions\Fga\WriteFgaTuples;
use App\Http\Props\Shared\HelpProps;
use App\Platform\Actions\ActionRefused;
use App\Platform\Console\Vocabulary;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Kernel\Authorization\Exceptions\InvalidTuple;
use Cbox\Id\Kernel\Authorization\Schema\AuthorizationSchema;
use Cbox\Id\Kernel\Authorization\Schema\RelationDefinition;
use Cbox\Id\Kernel\Authorization\ValueObjects\SubjectRef;
use Cbox\Id\Kernel\Authorization\ValueObjects\Tuple;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * CONSOLE › USERS & ORGS › FINE-GRAINED AUTHORIZATION — the environment's own relationship
 * model, as the team building the app sees it.
 *
 * Two pages:
 *
 *  - the overview (`environment.fga`): the schema's types and relations as they read, the
 *    tuples stored (filtered, paged), a form to write or delete one, and a CHECK PLAYGROUND
 *    — "may user:alice view document:readme?" answered by the same action an app's
 *    backend calls, with the revision it was decided at. The playground is a GET form, so
 *    a question is a link that can be pasted into a ticket;
 *  - the schema editor (`environment.fga.schema`), behind `env.sudo` like every page that
 *    changes how the environment decides access: validate as you go, then save. The schema
 *    decides every check at once, which is why saving it is the one Critical action here.
 *
 * Every write is an action (`App\Actions\Fga\*`) — the same the management API, MCP and the
 * CLI run, with the same trail line.
 */
final readonly class FgaController extends ConsoleController
{
    private const int PER_PAGE = 50;

    /** The model the schema editor opens with when the environment has none yet. */
    public const string STARTER_SCHEMA = <<<'SCHEMA'
        # Documents live in folders; a folder's viewers can read everything in it.
        type user

        type group
          relation member: [user, group#member]

        type folder
          relation parent: [folder]
          relation owner: [user]
          relation editor: [user, group#member] or owner or editor from parent
          relation viewer: [user, group#member] or editor or viewer from parent

        type document
          relation parent: [folder]
          relation owner: [user]
          relation editor: [user, group#member] or owner or editor from parent
          relation viewer: [user, group#member] or editor or viewer from parent
        SCHEMA;

    public function index(Request $request): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        $schema = $this->runAction(ShowFgaSchema::class, [])->payload ?? [];

        $filters = [
            'resource' => $request->string('resource')->toString(),
            'subject' => $request->string('subject')->toString(),
        ];

        $tuples = $this->runAction(ListFgaTuples::class, array_filter([
            ...self::resourceFilter($filters['resource']),
            ...self::subjectFilter($filters['subject']),
            'limit' => self::PER_PAGE,
            'after' => $request->string('after')->toString(),
        ], static fn (int|string $value): bool => $value !== ''));

        $nextCursor = $tuples->meta['next_cursor'] ?? null;

        return $this->page('console/fga/index', Vocabulary::FINE_GRAINED_AUTHORIZATION, [
            'help' => HelpProps::for(HelpTopic::FineGrainedAuthorization),
            'schema' => [
                'defined' => (bool) ($schema['defined'] ?? false),
                'version' => is_int($schema['version'] ?? null) ? $schema['version'] : 0,
                'updatedAt' => is_string($schema['updated_at'] ?? null) ? $schema['updated_at'] : null,
                'consistencyToken' => is_string($schema['consistency_token'] ?? null) ? $schema['consistency_token'] : '',
                'types' => self::types(is_array($schema['types'] ?? null) ? $schema['types'] : []),
            ],
            'tuples' => array_map(static fn (mixed $tuple): string => is_array($tuple) && is_string($tuple['tuple'] ?? null) ? $tuple['tuple'] : '', $tuples->payload ?? []),
            'filters' => $filters,
            'nextHref' => is_string($nextCursor) ? $request->fullUrlWithQuery(['after' => $nextCursor]) : null,
            'firstHref' => $request->filled('after') ? $request->fullUrlWithQuery(['after' => null]) : null,
            'check' => $this->playground($request),
            'checkHref' => route('environment.fga'),
            'schemaHref' => route('environment.fga.schema'),
            'storeTupleHref' => route('environment.fga.tuples.store'),
            'destroyTupleHref' => route('environment.fga.tuples.destroy'),
        ]);
    }

    /**
     * The editor. `?draft=` is the Validate button: the text being edited, checked by the
     * same read action an agent calls and handed back beside it — a read, so a GET, and the
     * page's own text is never replaced by it.
     */
    public function schema(Request $request): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        $schema = $this->runAction(ShowFgaSchema::class, [])->payload ?? [];
        $defined = (bool) ($schema['defined'] ?? false);
        $draft = $request->string('draft')->toString();
        $validation = $draft === '' ? null : $this->runAction(ValidateFgaSchema::class, ['schema' => $draft])->payload;

        return $this->page('console/fga/schema', 'Authorization schema', [
            'help' => HelpProps::for(HelpTopic::FineGrainedAuthorization),
            'source' => $defined && is_string($schema['schema'] ?? null) ? $schema['schema'] : self::STARTER_SCHEMA,
            'defined' => $defined,
            'version' => is_int($schema['version'] ?? null) ? $schema['version'] : 0,
            'validation' => $validation,
            'updateHref' => route('environment.fga.schema.update'),
            'validateHref' => route('environment.fga.schema'),
            'indexHref' => route('environment.fga'),
        ]);
    }

    public function updateSchema(Request $request): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $result = $this->act(UpdateFgaSchema::class, ['schema' => $request->string('schema')->toString()], ['schema' => 'schema'], 'schema');

        return $result instanceof RedirectResponse
            ? $result
            : to_route('environment.fga')->with('status', 'Schema saved. Every check is decided by it from now on.');
    }

    public function storeTuple(Request $request): RedirectResponse
    {
        return $this->tuple($request, WriteFgaTuples::class, 'Tuple written.');
    }

    public function destroyTuple(Request $request): RedirectResponse
    {
        return $this->tuple($request, DeleteFgaTuples::class, 'Tuple deleted.');
    }

    /**
     * One tuple, typed in the notation (`document:readme#viewer@user:alice`), through the
     * batch action with a batch of one.
     *
     * @param  class-string<WriteFgaTuples|DeleteFgaTuples>  $action
     */
    private function tuple(Request $request, string $action, string $done): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        try {
            $tuple = Tuple::parse(trim($request->string('tuple')->toString()));
        } catch (InvalidTuple $invalid) {
            return back()->withInput()->withErrors(['tuple' => $invalid->getMessage()]);
        }

        $result = $this->act($action, ['tuples' => [$tuple->toArray()]], ['tuples' => 'tuple'], 'tuple');

        return $result instanceof RedirectResponse ? $result : back()->with('status', $done);
    }

    /**
     * The playground's question, from the query string, and its answer — through the check
     * action itself, so the console answers exactly what the API would.
     *
     * @return array{resource: string, relation: string, subject: string, consistencyToken: string, asked: bool, allowed: bool|null, error: string|null, decidedAt: string|null}
     */
    private function playground(Request $request): array
    {
        $resource = trim($request->string('check_resource')->toString());
        $relation = trim($request->string('check_relation')->toString());
        $subject = trim($request->string('check_subject')->toString());
        $token = trim($request->string('check_token')->toString());

        $check = [
            'resource' => $resource,
            'relation' => $relation,
            'subject' => $subject,
            'consistencyToken' => $token,
            'asked' => false,
            'allowed' => null,
            'error' => null,
            'decidedAt' => null,
        ];

        if ($resource === '' || $relation === '' || $subject === '') {
            return $check;
        }

        $check['asked'] = true;
        [$type, $id] = array_pad(explode(':', $resource, 2), 2, '');

        try {
            $who = SubjectRef::parse($subject);
        } catch (InvalidTuple $invalid) {
            return [...$check, 'error' => $invalid->getMessage()];
        }

        if ($type === '' || $id === '') {
            return [...$check, 'error' => 'Write the resource as `type:id`, e.g. `document:readme`.'];
        }

        try {
            $answer = $this->runAction(CheckFgaRelation::class, array_filter([
                'resource_type' => $type,
                'resource_id' => $id,
                'relation' => $relation,
                'subject_type' => $who->type,
                'subject_id' => $who->id,
                'subject_relation' => $who->relation,
                'consistency_token' => $token,
            ], static fn (?string $value): bool => $value !== null && $value !== ''));
        } catch (ActionRefused $refused) {
            return [...$check, 'error' => $refused->getMessage()];
        } catch (ValidationException $invalid) {
            return [...$check, 'error' => $invalid->getMessage()];
        }

        $payload = $answer->payload ?? [];

        return [
            ...$check,
            'allowed' => ($payload['allowed'] ?? false) === true,
            'decidedAt' => is_string($payload['consistency_token'] ?? null) ? $payload['consistency_token'] : null,
        ];
    }

    /**
     * The schema's types as the overview lists them: each relation with its definition as
     * it reads in the schema language.
     *
     * @param  array<mixed>  $types
     * @return list<array{name: string, relations: list<array{name: string, definition: string}>}>
     */
    private static function types(array $types): array
    {
        if ($types === []) {
            return [];
        }

        $listed = [];

        foreach (AuthorizationSchema::fromArray(['types' => $types])->types as $type) {
            $listed[] = [
                'name' => $type->name,
                'relations' => array_values(array_map(static fn (RelationDefinition $relation): array => [
                    'name' => $relation->name,
                    'definition' => $relation->rewrite->toDsl(),
                ], $type->relations)),
            ];
        }

        return $listed;
    }

    /**
     * `document:readme`, `document`, or nothing.
     *
     * @return array<string, string>
     */
    private static function resourceFilter(string $resource): array
    {
        if ($resource === '') {
            return [];
        }

        [$type, $id] = array_pad(explode(':', $resource, 2), 2, '');

        return array_filter(['resource_type' => $type, 'resource_id' => $id], static fn (string $value): bool => $value !== '');
    }

    /**
     * `user:alice`, `group:eng#member`, `user`, or nothing.
     *
     * @return array<string, string>
     */
    private static function subjectFilter(string $subject): array
    {
        if ($subject === '') {
            return [];
        }

        [$type, $rest] = array_pad(explode(':', $subject, 2), 2, '');
        [$id, $relation] = array_pad(explode('#', $rest, 2), 2, '');

        return array_filter(['subject_type' => $type, 'subject_id' => $id, 'subject_relation' => $relation], static fn (string $value): bool => $value !== '');
    }
}
