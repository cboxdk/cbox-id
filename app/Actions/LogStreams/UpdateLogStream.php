<?php

declare(strict_types=1);

namespace App\Actions\LogStreams;

use App\Http\Resources\Environment\LogStreamResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Integrations\IntegrationAudit;
use App\Platform\Integrations\IntegrationReach;
use App\Platform\Integrations\LogStreamDestinations;
use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\LaravelSiem\Contracts\LogStreams;
use Cbox\LaravelSiem\Enums\AuthScheme;
use Cbox\LaravelSiem\Enums\Destination;
use Cbox\LaravelSiem\Exceptions\InvalidStreamConfiguration;
use Cbox\LaravelSiem\Exceptions\UnsafeStreamUrl;

/**
 * Change a stream: its name, where it ships and with what credential — or disable and
 * resume it, by the state it should END in (a retried "toggle" undoes itself, `enabled`
 * does not; the console sends the opposite of what it shows).
 *
 * THE SETTINGS go through {@see LogStreams::update()}, which re-validates the whole set and
 * resets the circuit breaker, so a stream showing `action_required` because its key was
 * revoked is tried again on the next pump once the new key is in. `options` MERGE over the
 * stored ones — send only what changes, and null to remove a key (an access key ID, when an
 * S3 stream moves to an assumed role); a stream that changes destination starts from none.
 * The external ID of an assumed-role stream is kept: the customer's trust policy names it.
 * `secret` rotates the credential and is never echoed; left out, the stored one stays —
 * except where it can no longer apply: a new destination does not inherit the old one's
 * key, and an assumed-role stream holds none. A stream that ends up on HMAC with no key
 * gets one generated, revealed once.
 *
 * Disabling stops deliveries and KEEPS the pending rows, which is the difference between
 * pausing a feed and losing part of an audit trail. It is also exactly what someone covering
 * their tracks would do first — and repointing the feed is the quieter version of it — so
 * this is CRITICAL, where approval policies look, and never silent: every change lands on
 * the trail, and in every stream still running. Asked to enable a stream that is already
 * enabled (or disable one already off), it changes nothing and records nothing.
 */
#[AsAction(
    name: 'log_streams.update',
    summary: 'Change an audit log stream\'s name, destination, endpoint, options or credential (re-validated; resets its circuit breaker), or disable (enabled: false) / resume (enabled: true) it.',
    scope: 'log_streams:write',
    danger: Danger::Critical,
    schema: 'LogStream',
    tag: 'Log streams',
    rest: ['PATCH', '/log-streams/{id}'],
    consoleRoutes: ['audit-streams.toggle', 'environment.audit-streams.toggle', 'audit-streams.update', 'environment.audit-streams.update'],
    consoleGate: ConsoleGate::Administer,
    redact: ['secret'],
)]
final readonly class UpdateLogStream implements Action
{
    /** The inputs that are a stream's destination settings, re-validated as a set. */
    private const array SETTINGS = ['destination', 'endpoint_url', 'auth', 'secret', 'options'];

    public function __construct(
        private LogStreams $streams,
        private IntegrationAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The log stream id.'),
            Field::string('name')->max(190),
            Field::string('destination')->oneOf(array_map(static fn (Destination $destination): string => $destination->value, Destination::cases()))->describe('Changing it starts the stream\'s options and credential afresh.'),
            Field::string('endpoint_url')->nullable()->max(2048)->format('uri')->describe('An HTTP collector\'s URL; for Datadog, S3 and GCS empty means the destination\'s own endpoint.'),
            Field::string('auth')->oneOf(array_map(static fn (AuthScheme $scheme): string => $scheme->value, AuthScheme::cases()))->describe('An HTTP collector only.'),
            Field::string('secret')->nullable()->max(8192)->describe('A new credential (token, API key, secret access key or service-account JSON key). Left out, the current one is kept. Never echoed.'),
            LogStreamDestinations::optionsField(),
            Field::boolean('enabled')->describe('True to deliver; false to stop, keeping what is pending.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $stream = OwnedStreams::find($context);
        $organizationId = $stream->getAttribute('organization_id');
        $organizationId = is_string($organizationId) ? $organizationId : null;
        $generated = null;

        $attributes = $this->changes($context, $stream, $generated);

        if ($attributes !== []) {
            try {
                $this->streams->update($stream->id, $attributes);
            } catch (UnsafeStreamUrl) {
                throw ActionRefused::because('unsafe_url', 'That URL is not allowed — it must be a public endpoint.', 'endpoint_url');
            } catch (InvalidStreamConfiguration $invalid) {
                throw LogStreamDestinations::refusal($invalid);
            }

            $stream->refresh();

            $this->audit->record(IntegrationAudit::LOG_STREAM_UPDATED, 'log_stream', $stream->id, $organizationId, $context->actor(), [
                'name' => $stream->name,
                'destination' => $stream->destination->value,
                'endpoint_url' => $stream->endpoint_url,
                'options' => $stream->destinationOptions(),
                // What was asked to change, by name — `secret` among them when it was
                // rotated. Never a value of one.
                'changed' => array_values(array_intersect(['name', ...self::SETTINGS], array_keys($context->input))),
            ]);
        }

        if ($context->has('enabled') && $context->boolean('enabled') !== $stream->enabled) {
            $enabled = $context->boolean('enabled');

            // The registry has no enable verb; its update() seam flips the attribute
            // rather than this writing the column behind its back.
            $enabled
                ? $this->streams->update($stream->id, ['enabled' => true])
                : $this->streams->disable($stream->id);

            $stream->refresh();

            $this->audit->record($enabled ? IntegrationAudit::LOG_STREAM_ENABLED : IntegrationAudit::LOG_STREAM_DISABLED, 'log_stream', $stream->id, $organizationId, $context->actor(), [
                'name' => $stream->name,
                'endpoint_url' => $stream->endpoint_url,
            ]);
        }

        return ActionResult::item($stream, LogStreamResource::from($stream, $generated));
    }

    /**
     * The attributes to hand the registry — empty when nothing but `enabled` (or nothing at
     * all) was asked for. `$generated` receives an HMAC key this made up, to reveal once.
     *
     * @return array<string, mixed>
     *
     * @throws ActionRefused
     */
    private function changes(ActionContext $context, AuditStream $stream, ?string &$generated): array
    {
        $attributes = [];

        if ($context->has('name') && trim($context->string('name')) !== $stream->name) {
            $attributes['name'] = trim($context->string('name'));
        }

        if (array_intersect(self::SETTINGS, array_keys($context->input)) === []) {
            return $attributes;
        }

        $destination = $context->has('destination') ? Destination::from($context->string('destination')) : $stream->destination;
        $moved = $destination !== $stream->destination;

        // Merge-patch over what is stored, on the same destination; a new destination's
        // settings start from nothing — the old ones were never its.
        $options = $moved ? [] : $stream->destinationOptions();

        foreach ($context->array('options') as $key => $value) {
            $options[$key] = $value;
        }

        $options = array_filter($options, static fn (mixed $value): bool => $value !== null);

        // Always handed over: `options` is one of the registry's settings, so the whole set
        // is re-validated and the breaker reset whatever else changed.
        $attributes['destination'] = $destination;
        $attributes['options'] = $options;

        if ($context->has('endpoint_url')) {
            $endpointUrl = trim($context->string('endpoint_url'));

            if ($endpointUrl !== '') {
                IntegrationReach::assertUrl($endpointUrl, 'endpoint_url');
            }

            $attributes['endpoint_url'] = $endpointUrl;
        } elseif ($moved) {
            // The old destination's endpoint is not the new one's: a cloud destination
            // derives its own, and a collector is refused without one.
            $attributes['endpoint_url'] = '';
        }

        LogStreamDestinations::assertRoleAssumable($destination, $options);

        if ($context->has('secret')) {
            $attributes['secret'] = $context->nullableString('secret');
        } elseif ($moved || ($destination === Destination::S3 && isset($options['role_arn']))) {
            // A Datadog API key is not a Splunk token, and an assumed role holds no key.
            $attributes['secret'] = null;
        }

        if (! $destination->requiresOptions()) {
            $auth = $context->has('auth')
                ? AuthScheme::from($context->string('auth'))
                : ($moved ? $destination->defaultAuth() : $stream->auth);
            $attributes['auth'] = $auth;

            $secret = array_key_exists('secret', $attributes) ? $attributes['secret'] : $stream->secret;

            if ($auth === AuthScheme::Hmac && ($secret === null || $secret === '')) {
                $generated = bin2hex(random_bytes(32));
                $attributes['secret'] = $generated;
            }
        }

        return $attributes;
    }
}
