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
 * Start mirroring the audit trail to a SIEM — an HTTP collector (Splunk, Elastic, Graylog,
 * CEF, JSON), Datadog Logs, or an Amazon S3 / Google Cloud Storage bucket.
 *
 * CRITICAL: it ships every entry it covers — members joining, sign-ins failing, roles
 * changing — to a destination the caller chose, and for the HMAC scheme generates the
 * signing key, revealed once. An environment-wide stream (`environment_wide`) carries
 * every organization's entries, which only the environment's authority may set up.
 *
 * THE CLOUD DESTINATIONS take typed `options` and derive their endpoint (the Datadog site's
 * intake, the bucket's region); `endpoint_url` is only for an S3-compatible store or a
 * proxy. `secret` is whatever the destination authenticates with — the API key, the secret
 * access key, the service-account JSON key — and an assumed-role S3 stream has none: the
 * platform assumes the role with its own identity, and the answer's `external_id` is what
 * the role's trust policy must require. The package validates the settings before anything
 * is stored; its refusal lands on the field it names ({@see LogStreamDestinations::refusal()}).
 *
 * {@see LogStreams} knows nothing about organizations — the column is this platform's and
 * so is the boundary — so the owner is stamped after create(), inside the same
 * transaction: a stream never exists, even for a moment, carrying more than it should.
 *
 * `secret` in the answer is the key the platform GENERATED; a credential the caller supplied
 * is not echoed back to them. It is in `redact`, so a replay carries null.
 */
#[AsAction(
    name: 'log_streams.create',
    summary: 'Stream the audit trail to a SIEM (Splunk, Elastic, Graylog, CEF, JSON), Datadog, or an S3 or GCS bucket. A generated HMAC key is returned once.',
    scope: 'log_streams:write',
    danger: Danger::Critical,
    schema: 'LogStream',
    tag: 'Log streams',
    rest: ['POST', '/log-streams'],
    status: 201,
    consoleRoutes: ['audit-streams.store', 'environment.audit-streams.store'],
    consoleGate: ConsoleGate::Administer,
    redact: ['secret'],
)]
final readonly class CreateLogStream implements Action
{
    public function __construct(
        private LogStreams $streams,
        private IntegrationAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('name')->required()->max(190),
            Field::string('destination')->required()->oneOf(array_map(static fn (Destination $destination): string => $destination->value, Destination::cases())),
            Field::string('endpoint_url')->nullable()->max(2048)->format('uri')->describe('An HTTP collector: the public URL entries are POSTed to (required). Datadog, S3 and GCS: leave out for the destination\'s own endpoint, or an https URL of an S3-compatible store (MinIO, R2).'),
            Field::string('auth')->oneOf(array_map(static fn (AuthScheme $scheme): string => $scheme->value, AuthScheme::cases()))->describe('An HTTP collector: how the endpoint is authenticated. Left out, the destination\'s default. The cloud destinations authenticate their own way.'),
            Field::string('secret')->nullable()->max(8192)->describe('The credential, never echoed: the bearer or Splunk token (HTTP collectors; left out with `hmac`, a key is generated and returned once), the Datadog API key, the S3 secret access key (none with `role_arn`), or the GCS service-account JSON key.'),
            LogStreamDestinations::optionsField(),
            ...IntegrationReach::ownerFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = IntegrationReach::owner($context);
        $destination = Destination::from($context->string('destination'));
        $endpointUrl = trim($context->string('endpoint_url'));
        $supplied = $context->nullableString('secret');
        $auth = $context->nullableString('auth');
        $options = LogStreamDestinations::given($context->array('options'));

        if ($endpointUrl !== '') {
            IntegrationReach::assertUrl($endpointUrl, 'endpoint_url');
        }

        LogStreamDestinations::assertRoleAssumable($destination, $options);

        try {
            $registered = $this->streams->create(
                name: trim($context->string('name')),
                destination: $destination,
                endpointUrl: $endpointUrl,
                secret: $supplied,
                auth: $auth === null ? null : AuthScheme::from($auth),
                options: $options,
            );
        } catch (UnsafeStreamUrl) {
            throw ActionRefused::because('unsafe_url', 'That URL is not allowed — it must be a public endpoint.', 'endpoint_url');
        } catch (InvalidStreamConfiguration $invalid) {
            throw LogStreamDestinations::refusal($invalid);
        }

        if ($organizationId !== null) {
            AuditStream::query()->whereKey($registered->stream->id)->update(['organization_id' => $organizationId]);
        }

        $stream = AuditStream::query()->findOrFail($registered->stream->id);

        $this->audit->record(IntegrationAudit::LOG_STREAM_CREATED, 'log_stream', $stream->id, $organizationId, $context->actor(), [
            'name' => $stream->name,
            'destination' => $stream->destination->value,
            'endpoint_url' => $stream->endpoint_url,
            // The settings, never the credential: a bucket and a region are where the trail
            // went, which is what somebody reading this entry needs to know.
            'options' => $stream->destinationOptions(),
        ]);

        $generated = $supplied === null ? $registered->secret : null;

        return ActionResult::item($registered, LogStreamResource::from($stream, $generated));
    }
}
