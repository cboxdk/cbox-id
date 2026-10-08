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
use Carbon\CarbonImmutable;
use Cbox\LaravelSiem\Contracts\StreamTester;
use Cbox\LaravelSiem\SinkStreamTester;

/**
 * Send ONE marked test event to a log stream now, and say whether it arrived — the question
 * somebody who has just pasted a Splunk token, a Datadog API key or a bucket policy into a
 * form actually has.
 *
 * The package's {@see StreamTester} ships it exactly as a real entry is — the destination's
 * own framing, credentials and egress guard, the same sink the queued pump uses — so a
 * success here means the real entries will land too. Synchronous and NOT through the
 * outbox: it is not an audit entry, it must not be retried into the customer's SIEM an hour
 * later, and the answer is only useful while the person is still looking at it. Its action
 * is `siem.stream.test` ({@see SinkStreamTester::ACTION}), so a SIEM rule can drop it.
 *
 * A refusal from the destination is the answer, not an error: `delivered: false`, `failure`
 * saying whether retrying could help (`transient`) or somebody has to fix the stream
 * (`authentication`, `configuration`), and the destination's reason, scrubbed of the
 * stream's secret. A success also clears an `action_required` stream: it works now, so its
 * pending entries go out on the next pump instead of waiting out a cooldown.
 */
#[AsAction(
    name: 'log_streams.test',
    summary: 'Send one test event to a log stream now and report whether the destination accepted it, and if not, why.',
    scope: 'log_streams:write',
    danger: Danger::Write,
    schema: 'LogStreamTest',
    tag: 'Log streams',
    rest: ['POST', '/log-streams/{id}/test'],
    consoleRoutes: ['audit-streams.test', 'environment.audit-streams.test'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class TestLogStream implements Action
{
    public function __construct(private StreamTester $tester) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The log stream\'s id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $stream = OwnedStreams::find($context);
        $result = $this->tester->test($stream);

        return ActionResult::item($stream, [
            'id' => $stream->id,
            'delivered' => $result->delivered,
            'failure' => $result->failure?->value,
            'error' => $result->error,
            'tested_at' => CarbonImmutable::now()->toIso8601ZuluString(),
        ]);
    }
}
