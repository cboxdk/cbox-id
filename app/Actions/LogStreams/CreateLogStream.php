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
use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\LaravelSiem\Contracts\LogStreams;
use Cbox\LaravelSiem\Enums\AuthScheme;
use Cbox\LaravelSiem\Enums\Destination;
use Cbox\LaravelSiem\Exceptions\UnsafeStreamUrl;

/**
 * Start mirroring the audit trail to a SIEM.
 *
 * CRITICAL: it ships every entry it covers — members joining, sign-ins failing, roles
 * changing — to an address the caller chose, and for the HMAC scheme generates the
 * signing key, revealed once. An environment-wide stream (`environment_wide`) carries
 * every organization's entries, which only the environment's authority may set up.
 *
 * {@see LogStreams} knows nothing about organizations — the column is this platform's and
 * so is the boundary — so the owner is stamped after create(), inside the same
 * transaction: a stream never exists, even for a moment, carrying more than it should.
 *
 * `secret` in the answer is the key the platform GENERATED; a token the caller supplied is
 * not echoed back to them. It is in `redact`, so a replay carries null.
 */
#[AsAction(
    name: 'log_streams.create',
    summary: 'Stream the audit trail to a SIEM (Splunk, Elastic, Graylog, CEF, JSON). A generated HMAC key is returned once.',
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
            Field::string('destination')->required()->oneOf(array_map(static fn (Destination $destination): string => $destination->value, Destination::httpCollectors())),
            Field::string('endpoint_url')->required()->max(2048)->format('uri')->describe('A public URL the entries are POSTed to.'),
            Field::string('auth')->oneOf(array_map(static fn (AuthScheme $scheme): string => $scheme->value, AuthScheme::cases()))->describe('How the endpoint is authenticated. Left out, the destination\'s default.'),
            Field::string('secret')->nullable()->max(4096)->describe('The bearer or Splunk token the endpoint expects. Left out with `hmac`, a key is generated and returned once.'),
            ...IntegrationReach::ownerFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = IntegrationReach::owner($context);
        $endpointUrl = trim($context->string('endpoint_url'));
        $supplied = $context->nullableString('secret');
        $auth = $context->nullableString('auth');

        IntegrationReach::assertUrl($endpointUrl, 'endpoint_url');

        try {
            $registered = $this->streams->create(
                trim($context->string('name')),
                Destination::from($context->string('destination')),
                $endpointUrl,
                $supplied,
                $auth === null ? null : AuthScheme::from($auth),
            );
        } catch (UnsafeStreamUrl) {
            throw ActionRefused::because('unsafe_url', 'That URL is not allowed — it must be a public endpoint.', 'endpoint_url');
        }

        if ($organizationId !== null) {
            AuditStream::query()->whereKey($registered->stream->id)->update(['organization_id' => $organizationId]);
        }

        $stream = AuditStream::query()->findOrFail($registered->stream->id);

        $this->audit->record(IntegrationAudit::LOG_STREAM_CREATED, 'log_stream', $stream->id, $organizationId, $context->actor(), [
            'name' => $stream->name,
            'destination' => $stream->destination->value,
            'endpoint_url' => $stream->endpoint_url,
        ]);

        $generated = $supplied === null ? $registered->secret : null;

        return ActionResult::item($registered, LogStreamResource::from($stream, $generated));
    }
}
