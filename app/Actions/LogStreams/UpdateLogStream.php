<?php

declare(strict_types=1);

namespace App\Actions\LogStreams;

use App\Http\Resources\Environment\LogStreamResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Integrations\IntegrationAudit;
use Cbox\LaravelSiem\Contracts\LogStreams;

/**
 * Disable or resume a stream, by the state it should END in — a retried "toggle" undoes
 * itself, `enabled` does not; the console sends the opposite of what it shows.
 *
 * Disabling stops deliveries and KEEPS the pending rows, which is the difference between
 * pausing a feed and losing part of an audit trail. It is also exactly what someone covering
 * their tracks would do first — so it is CRITICAL, where approval policies look, and never
 * silent: the entry lands on the trail, and in every stream still running. Asked for the
 * state it is already in, it changes nothing and records nothing.
 */
#[AsAction(
    name: 'log_streams.update',
    summary: 'Disable (enabled: false) or resume (enabled: true) an audit log stream. Disabled, entries are kept and delivered on resume.',
    scope: 'log_streams:write',
    danger: Danger::Critical,
    schema: 'LogStream',
    tag: 'Log streams',
    rest: ['PATCH', '/log-streams/{id}'],
    consoleRoutes: ['audit-streams.toggle', 'environment.audit-streams.toggle'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class UpdateLogStream implements Action
{
    public function __construct(
        private LogStreams $streams,
        private IntegrationAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The log stream id.'),
            Field::boolean('enabled')->required()->describe('True to deliver; false to stop, keeping what is pending.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $stream = OwnedStreams::find($context);
        $enabled = $context->boolean('enabled');

        if ($enabled !== $stream->enabled) {
            // The registry has no enable verb; its update() seam flips the attribute
            // rather than this writing the column behind its back.
            $enabled
                ? $this->streams->update($stream->id, ['enabled' => true])
                : $this->streams->disable($stream->id);

            $stream->refresh();

            $organizationId = $stream->getAttribute('organization_id');

            $this->audit->record($enabled ? IntegrationAudit::LOG_STREAM_ENABLED : IntegrationAudit::LOG_STREAM_DISABLED, 'log_stream', $stream->id, is_string($organizationId) ? $organizationId : null, $context->actor(), [
                'name' => $stream->name,
                'endpoint_url' => $stream->endpoint_url,
            ]);
        }

        return ActionResult::item($stream, LogStreamResource::from($stream));
    }
}
