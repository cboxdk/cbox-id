<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\LogStreams\CreateLogStream;
use App\Actions\LogStreams\DeleteLogStream;
use App\Actions\LogStreams\TestLogStream;
use App\Actions\LogStreams\UpdateLogStream;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\LogStreamRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\Vocabulary;
use App\Platform\Help\HelpTopic;
use App\Platform\Integrations\LogStreamDestinations;
use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\LaravelSiem\Enums\AuthScheme;
use Cbox\LaravelSiem\Enums\DatadogSite;
use Cbox\LaravelSiem\Enums\Destination;
use Cbox\LaravelSiem\Support\DestinationSettings;
use Cbox\LaravelSiem\ValueObjects\RegisteredStream;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * CONSOLE › LOG STREAMING — mirroring this environment's hash-chained audit trail out to a
 * SIEM: an HTTP collector (Splunk, Elastic, Graylog, CEF, JSON), Datadog Logs, or an Amazon
 * S3 or Google Cloud Storage bucket. Delivery is at-least-once and environment-isolated.
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
 * history entry. A credential somebody typed — a token, an API key, a secret access key, a
 * service-account key — is never sent back to the browser at all, not even on the edit
 * form: that one only says whether there is one.
 *
 * Every write is an ACTION (`App\Actions\LogStreams\*`), the same the management API's
 * `/v1/log-streams` and MCP run. Disabling a stream — the first thing somebody covering
 * their tracks would do — used to leave no line on the trail; now every create, edit,
 * disable, resume and delete records who did it, from either door.
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
        'datadog' => 'Datadog',
        's3' => 'Amazon S3',
        'gcs' => 'Google Cloud Storage',
    ];

    /** Auth scheme value => what the endpoint is presented with. */
    private const SCHEMES = [
        'none' => 'None',
        'bearer' => 'Bearer token',
        'splunk' => 'Splunk',
        'hmac' => 'HMAC (generated key)',
    ];

    /** Option key => its label on the stream's page, in its vendor's words. */
    private const OPTION_LABELS = [
        'site' => 'Datadog site',
        'service' => 'Service',
        'source' => 'Source (ddsource)',
        'tags' => 'Tags',
        'hostname' => 'Hostname',
        'bucket' => 'Bucket',
        'region' => 'AWS Region',
        'prefix' => 'Prefix',
        'access_key_id' => 'Access key ID',
        'role_arn' => 'IAM role ARN',
        'external_id' => 'External ID',
        'sse' => 'Server-side encryption',
        'kms_key_id' => 'AWS KMS key',
        'path_style' => 'Path-style addressing',
        'gzip' => 'Compression',
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
                'health' => $stream->health()->value,
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
            ...$this->choices(),
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

    public function store(LogStreamRequest $request): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        // Taken from the scope, never from a field: there is no form control for it,
        // because there is no question to ask. See the class docblock.
        $organizationId = $this->scope->plane() === ConsolePlane::Environment
            ? null
            : $this->scope->requireOrganizationId();

        $destination = $request->destination();

        $input = [
            'name' => $request->name(),
            'destination' => $destination->value,
            'endpoint_url' => $request->endpointUrl() === '' ? null : $request->endpointUrl(),
            'secret' => $request->secret(),
            'organization_id' => $organizationId,
            'environment_wide' => $organizationId === null,
        ];

        if ($destination->requiresOptions()) {
            $input['options'] = array_filter($request->options($destination), static fn (mixed $value): bool => $value !== null);
        } else {
            $input['auth'] = $request->scheme()->value;
        }

        $result = $this->act(CreateLogStream::class, $input, self::fields(), 'destination');

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

        // An assumed-role bucket receives nothing until its trust policy names this
        // stream's external ID — so the page opens on that, not on a success message.
        if (is_string($result->payload['external_id'] ?? null)) {
            $this->inertia->flash('awsSetup', $registered->stream->id);
        }

        return to_route($this->scope->routeName('audit-streams.show'), $registered->stream->id)
            ->with('status', 'Log stream created.');
    }

    public function show(string $stream): Response
    {
        $this->scope->assertMayAdminister();

        $model = $this->resolve($stream);
        $cloud = $model->destination->requiresOptions();

        return $this->page('console/log-streams/show', $model->name, [
            'stream' => [
                'id' => $model->id,
                'name' => $model->name,
                'destination' => self::DESTINATIONS[$model->destination->value],
                'destinationValue' => $model->destination->value,
                'endpointUrl' => $model->endpoint_url,
                'scheme' => $cloud ? null : self::SCHEMES[$model->auth->value],
                'options' => $this->optionRows($model),
                'enabled' => $model->enabled,
                'health' => $model->health()->value,
                'lastSuccessAt' => $model->last_success_at?->toIso8601String(),
                'lastError' => $model->last_error,
                'lastFailureKind' => $model->last_failure_kind?->value,
                'lastFailureAt' => $model->last_failure_at?->toIso8601String(),
            ],
            'aws' => $model->destination === Destination::S3 ? [
                'externalId' => LogStreamDestinations::externalId($model),
                'trustPolicy' => LogStreamDestinations::trustPolicy($model),
                'permissionsPolicy' => LogStreamDestinations::permissionsPolicy($model),
                'principalConfigured' => LogStreamDestinations::awsPrincipal() !== null,
            ] : null,
            'indexHref' => $this->url('audit-streams'),
            'urls' => [
                'edit' => $this->url('audit-streams.edit', $model->id),
                'test' => $this->url('audit-streams.test', $model->id),
                'toggle' => $this->url('audit-streams.toggle', $model->id),
                'destroy' => $this->url('audit-streams.destroy', $model->id),
            ],
        ]);
    }

    public function edit(string $stream): Response
    {
        $this->scope->assertMayAdminister();

        $model = $this->resolve($stream);
        $options = $model->destinationOptions();
        $default = app(DestinationSettings::class)->defaultEndpoint($model->destination, $options);

        return $this->page('console/log-streams/edit', 'Edit '.$model->name, [
            ...$this->choices(),
            'stream' => [
                'id' => $model->id,
                'name' => $model->name,
                'destination' => $model->destination->value,
                // A cloud destination on its own endpoint shows an empty field — the endpoint
                // follows the site or region — so only a custom one is something to edit.
                'endpointUrl' => $model->endpoint_url === $default ? '' : $model->endpoint_url,
                'scheme' => $model->auth->value,
                'options' => $this->formOptions($options),
                // Whether there IS a credential, never the credential: the field stays empty
                // and empty keeps it.
                'hasSecret' => $model->secret !== null && $model->secret !== '',
            ],
            'showHref' => $this->url('audit-streams.show', $model->id),
            'updateHref' => $this->url('audit-streams.update', $model->id),
        ]);
    }

    public function update(LogStreamRequest $request, string $stream): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $model = $this->resolve($stream);
        $destination = $request->destination();

        $input = [
            'id' => $model->id,
            'name' => $request->name(),
            'destination' => $destination->value,
            'endpoint_url' => $request->endpointUrl(),
        ];

        if ($request->secret() !== null) {
            $input['secret'] = $request->secret();
        }

        if ($destination->requiresOptions()) {
            // Every key the destination takes, an emptied one as null: the action merges, so
            // a field cleared on the form is removed rather than kept.
            $input['options'] = $request->options($destination);
        } else {
            $input['auth'] = $request->scheme()->value;
        }

        $result = $this->act(UpdateLogStream::class, $input, self::fields(), 'destination');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        $generated = $result->payload['secret'] ?? null;

        if (is_string($generated)) {
            $this->inertia->flash('newSecret', $generated);
        }

        return to_route($this->scope->routeName('audit-streams.show'), $model->id)
            ->with('status', 'Log stream saved. Delivery is tried again on the next run.');
    }

    /**
     * Send one test event now and put the answer beside the button — delivered, or the
     * destination's own words for why not, scrubbed of the credential.
     */
    public function test(string $stream): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $model = $this->resolve($stream);

        $result = $this->act(TestLogStream::class, ['id' => $model->id], [], 'test');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        $this->inertia->flash('streamTest', [
            'id' => $model->id,
            'delivered' => ($result->payload['delivered'] ?? false) === true,
            'failure' => is_string($result->payload['failure'] ?? null) ? $result->payload['failure'] : null,
            'error' => is_string($result->payload['error'] ?? null) ? $result->payload['error'] : null,
        ]);

        return back();
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
     * What the create and edit forms offer: every destination, the schemes an HTTP
     * collector may use, Datadog's sites, and whether an S3 stream may assume a role here.
     *
     * @return array<string, mixed>
     */
    private function choices(): array
    {
        return [
            'destinations' => array_map(
                static fn (Destination $destination): array => [
                    'value' => $destination->value,
                    'label' => self::DESTINATIONS[$destination->value],
                    'defaultAuth' => $destination->defaultAuth()->value,
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
            'datadogSites' => array_map(
                static fn (DatadogSite $site): array => ['value' => $site->value, 'label' => LogStreamDestinations::SITE_LABELS[$site->value]],
                DatadogSite::cases(),
            ),
            'assumedRoleAvailable' => LogStreamDestinations::assumedRoleAvailable(),
        ];
    }

    /**
     * The action's input names => this form's field names, so a refusal lands beside the
     * input it is about — an option's under its own name (`options.bucket`).
     *
     * @return array<string, string>
     */
    private static function fields(): array
    {
        $fields = ['name' => 'name', 'destination' => 'destination', 'endpoint_url' => 'endpointUrl', 'auth' => 'scheme', 'secret' => 'secret', 'options' => 'destination'];

        foreach (array_unique(array_merge(...array_values(LogStreamDestinations::OPTIONS))) as $key) {
            $fields['options.'.$key] = 'options.'.$key;
        }

        $fields['options.tags.*'] = 'options.tags';

        return $fields;
    }

    /**
     * The stream's settings as label/value rows for its page — nothing secret is among them.
     *
     * @return list<array{label: string, value: string}>
     */
    private function optionRows(AuditStream $stream): array
    {
        $rows = [];

        foreach ($stream->destinationOptions() as $key => $value) {
            if (! isset(self::OPTION_LABELS[$key]) || $key === 'external_id') {
                continue;
            }

            $rows[] = [
                'label' => self::OPTION_LABELS[$key],
                'value' => match (true) {
                    $key === 'gzip' => $value === true ? 'gzip' : 'None',
                    $key === 'site' && is_string($value) => LogStreamDestinations::SITE_LABELS[$value] ?? $value,
                    is_bool($value) => $value ? 'On' : 'Off',
                    is_scalar($value) => (string) $value,
                    default => '',
                },
            ];
        }

        return $rows;
    }

    /**
     * Stored options as the form holds them: strings, tags as one comma-separated line,
     * and the two switches as booleans.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, string|bool>
     */
    private function formOptions(array $options): array
    {
        $form = [];

        foreach ($options as $key => $value) {
            $form[$key] = match (true) {
                is_bool($value) => $value,
                is_array($value) => implode(', ', array_filter($value, is_string(...))),
                is_scalar($value) => (string) $value,
                default => '',
            };
        }

        return $form;
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
