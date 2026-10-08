<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\LogStreams\CreateLogStream;
use App\Actions\LogStreams\DeleteLogStream;
use App\Actions\LogStreams\UpdateLogStream;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\CreateLogStreamRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\Vocabulary;
use App\Platform\Help\HelpTopic;
use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\LaravelSiem\Enums\AuthScheme;
use Cbox\LaravelSiem\Enums\Destination;
use Cbox\LaravelSiem\ValueObjects\RegisteredStream;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * CONSOLE › LOG STREAMING — mirroring this environment's hash-chained audit trail out to a
 * SIEM. Delivery is at-least-once and environment-isolated.
 *
 * WHOSE TRAIL A STREAM SHIPS is the whole security of this page, and it is decided by the
 * PLANE rather than by a field. A stream with no organization receives EVERY organization's
 * entries in the environment — members joining, sign-ins failing, roles changing, for
 * tenants that are not yours. That is the operator's own compliance shipping and it belongs
 * to the environment plane; a tenant administrator gets a stream carrying their
 * organization and nothing else.
 *
 * OWNED, NOT DELIVERABLE. An organization is DELIVERED the environment's own streams'
 * attention and must never be able to manage them — scoping the list by the delivery
 * relation would show a tenant the operator's SIEM endpoint and offer them a pause button
 * for it. The difference between the two scopes is the control.
 *
 * The signing key is revealed exactly once, on the flash channel: only ciphertext is
 * persisted, so it can never be retrieved again, and props are written into the browser's
 * history entry.
 *
 * Every write is an ACTION (`App\Actions\LogStreams\*`), the same the management API's
 * `/v1/log-streams` and MCP run. Disabling a stream — the first thing somebody covering
 * their tracks would do — used to leave no line on the trail; now every create, disable,
 * resume and delete records who did it, from either door.
 */
final readonly class LogStreamController extends ConsoleController
{
    /**
     * Destination value => the name the vendor uses for it.
     *
     * TOTAL over the enum, and there is no fallback for that reason: a destination missing
     * from this map would render as its own snake_case value, which reads like a bug in
     * the page rather than a case somebody forgot. PHPStan holds the totality.
     */
    private const DESTINATIONS = [
        'splunk_hec' => 'Splunk HEC',
        'elastic_ecs' => 'Elastic (ECS)',
        'graylog_gelf' => 'Graylog (GELF)',
        'cef_http' => 'CEF over HTTP',
        'generic_json' => 'Generic JSON',
    ];

    /** Auth scheme value => what the endpoint is presented with. */
    private const SCHEMES = [
        'none' => 'None',
        'bearer' => 'Bearer token',
        'splunk' => 'Splunk',
        'hmac' => 'HMAC (generated key)',
    ];

    public function index(Request $request): Response
    {
        $this->scope->assertMayAdminister();

        $query = $this->owned()->orderByDesc('created_at');

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        $streams = $query->get();

        return $this->page('console/log-streams/index', Vocabulary::LOG_STREAMS, [
            'help' => HelpProps::for(HelpTopic::LogStreaming),
            'streams' => $streams->map(fn (AuditStream $stream): array => [
                'id' => $stream->id,
                'name' => $stream->name,
                'destination' => self::DESTINATIONS[$stream->destination->value],
                'endpointUrl' => $stream->endpoint_url,
                'enabled' => $stream->enabled,
                'href' => $this->url('audit-streams.show', $stream->id),
            ])->all(),
            'search' => $term,
            'createHref' => $this->url('audit-streams.create'),
        ]);
    }

    public function create(): Response
    {
        $this->scope->assertMayAdminister();

        return $this->page('console/log-streams/create', 'New log stream', [
            'destinations' => array_map(
                static fn (Destination $destination): array => [
                    'value' => $destination->value,
                    'label' => self::DESTINATIONS[$destination->value],
                ],
                Destination::cases(),
            ),
            'schemes' => array_map(
                static fn (AuthScheme $scheme): array => [
                    'value' => $scheme->value,
                    'label' => self::SCHEMES[$scheme->value],
                ],
                AuthScheme::cases(),
            ),
            /*
             * WHAT THIS STREAM WILL CARRY, said before it is created rather than inferred
             * from which console you happen to be in. The two planes mint materially
             * different things and the form is otherwise identical.
             */
            'shipsWholeEnvironment' => $this->scope->plane() === ConsolePlane::Environment,
            'indexHref' => $this->url('audit-streams'),
            'storeHref' => $this->url('audit-streams.store'),
        ]);
    }

    public function store(CreateLogStreamRequest $request): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        // Taken from the scope, never from a field: there is no form control for it,
        // because there is no question to ask. See the class docblock.
        $organizationId = $this->scope->plane() === ConsolePlane::Environment
            ? null
            : $this->scope->requireOrganizationId();

        $result = $this->act(CreateLogStream::class, [
            'name' => $request->name(),
            'destination' => $request->destination()->value,
            'endpoint_url' => $request->endpointUrl(),
            'auth' => $request->scheme()->value,
            'secret' => $request->secret(),
            'organization_id' => $organizationId,
            'environment_wide' => $organizationId === null,
        ], ['name' => 'name', 'destination' => 'destination', 'endpoint_url' => 'endpointUrl', 'auth' => 'scheme', 'secret' => 'secret'], 'endpointUrl');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var RegisteredStream $registered */
        $registered = $result->value;

        /*
         * A GENERATED HMAC key, revealed exactly once. Only ciphertext is persisted, so it
         * can never be retrieved again.
         *
         * Read off the action's answer, not off the registered stream: the stream hands back
         * whatever secret it was given, so a bearer or Splunk token the person TYPED came
         * straight back in a "copy this key" banner — a credential echoed into the flash for
         * no reason, from a form they could already read it off. The action's answer carries
         * only a key the platform made ({@see CreateLogStream}).
         */
        $generated = $result->payload['secret'] ?? null;

        if (is_string($generated)) {
            $this->inertia->flash('newSecret', $generated);
        }

        return to_route($this->scope->routeName('audit-streams.show'), $registered->stream->id)
            ->with('status', 'Log stream created.');
    }

    public function show(string $stream): Response
    {
        $this->scope->assertMayAdminister();

        $model = $this->resolve($stream);

        return $this->page('console/log-streams/show', $model->name, [
            'stream' => [
                'id' => $model->id,
                'name' => $model->name,
                'destination' => self::DESTINATIONS[$model->destination->value],
                'endpointUrl' => $model->endpoint_url,
                'scheme' => self::SCHEMES[$model->auth->value],
                'enabled' => $model->enabled,
            ],
            'indexHref' => $this->url('audit-streams'),
            'urls' => [
                'toggle' => $this->url('audit-streams.toggle', $model->id),
                'destroy' => $this->url('audit-streams.destroy', $model->id),
            ],
        ]);
    }

    /**
     * Disable or resume, said as one endpoint.
     *
     * Disabling stops deliveries and KEEPS the pending rows, which is the difference
     * between pausing a feed and losing part of an audit trail.
     */
    public function toggle(string $stream): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $model = $this->resolve($stream);
        $enable = ! $model->enabled;

        $result = $this->act(UpdateLogStream::class, ['id' => $model->id, 'enabled' => $enable]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return back()->with('status', $enable
            ? 'Stream resumed.'
            : 'Stream disabled — entries stop being delivered and are kept.');
    }

    public function destroy(string $stream): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $model = $this->resolve($stream);

        $result = $this->act(DeleteLogStream::class, ['id' => $model->id]);

        return $result instanceof RedirectResponse ? $result : to_route($this->scope->routeName('audit-streams'))
            ->with('status', 'Log stream deleted.');
    }

    /**
     * The streams this administrator OWNS — not the ones delivered to them.
     *
     * `ownedByOrganization()` and never the delivery scope: a tenant is delivered the
     * environment's own streams' attention, and scoping this list that way would show them
     * the operator's SIEM endpoint with a pause button beside it.
     *
     * @return Builder<AuditStream>
     */
    private function owned(): Builder
    {
        return AuditStream::query()->ownedByOrganization(
            $this->scope->plane() === ConsolePlane::Environment
                ? null
                : $this->scope->requireOrganizationId(),
        );
    }

    /**
     * The stream this page acts on, or a 404.
     *
     * Resolved within this plane's OWNERSHIP, so an id belonging to another organization —
     * or to the environment itself — 404s here rather than opening a page with a pause
     * button on somebody else's SIEM.
     */
    private function resolve(string $stream): AuditStream
    {
        $model = $this->owned()->whereKey($stream)->first();

        abort_if($model === null, 404);

        return $model;
    }
}
