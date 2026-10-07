<?php

declare(strict_types=1);

namespace App\Actions\LogStreams;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Integrations\IntegrationAudit;

/**
 * Delete a stream: nothing more is shipped to it. The entry keeps where it pointed — once
 * the row is gone, the trail is the only record of where the audit trail was going.
 */
#[AsAction(
    name: 'log_streams.delete',
    summary: 'Delete an audit log stream. Nothing more is delivered to its endpoint.',
    scope: 'log_streams:write',
    danger: Danger::Destructive,
    tag: 'Log streams',
    rest: ['DELETE', '/log-streams/{id}'],
    status: 204,
    consoleRoutes: ['audit-streams.destroy', 'environment.audit-streams.destroy'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class DeleteLogStream implements Action
{
    public function __construct(private IntegrationAudit $audit) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The log stream id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $stream = OwnedStreams::find($context);
        $organizationId = $stream->getAttribute('organization_id');

        $stream->delete();

        $this->audit->record(IntegrationAudit::LOG_STREAM_DELETED, 'log_stream', $stream->id, is_string($organizationId) ? $organizationId : null, $context->actor(), [
            'name' => $stream->name,
            'destination' => $stream->destination->value,
            'endpoint_url' => $stream->endpoint_url,
        ]);

        return ActionResult::none($stream);
    }
}
