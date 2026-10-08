<?php

declare(strict_types=1);

namespace App\Actions\LogStreams;

use App\Mail\MailText;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Carbon\CarbonImmutable;
use Cbox\LaravelSiem\Exceptions\StreamDeliveryFailed;
use Cbox\LaravelSiem\Support\FormatterFactory;
use Cbox\LaravelSiem\Support\SecretScrubber;
use Cbox\Siem\Contracts\StreamSink;
use Cbox\Siem\Enums\EventCategory;
use Cbox\Siem\Enums\Outcome;
use Cbox\Siem\Enums\Severity;
use Cbox\Siem\ValueObjects\SiemEvent;
use Cbox\Siem\ValueObjects\StreamTarget;
use Illuminate\Support\Str;

/**
 * Send ONE synthetic entry to a log stream now, and say whether it arrived — the question
 * somebody who has just pasted a Splunk token into a form actually has.
 *
 * Shipped exactly as a real entry is — the destination's own framing and authentication,
 * through the same SSRF-guarded sink the queued pump uses — so a success here means the
 * real entries will land too. Sent synchronously and NOT through the outbox: it is not an
 * audit entry, it must not be retried into the customer's SIEM an hour later, and the
 * answer is only useful while the person is still looking at it.
 *
 * A refusal from the destination is the answer, not an error: `delivered: false` with the
 * destination's reason, scrubbed of the stream's secret.
 */
#[AsAction(
    name: 'log_streams.test',
    summary: 'Send one test entry to a log stream now and report whether the SIEM accepted it.',
    scope: 'log_streams:write',
    danger: Danger::Write,
    schema: 'LogStreamTest',
    tag: 'Log streams',
    rest: ['POST', '/log-streams/{id}/test'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class TestLogStream implements Action
{
    /** The action name the test entry carries, so a SIEM rule can drop it. */
    public const string ACTION = 'log_stream.test';

    public function __construct(
        private StreamSink $sink,
        private FormatterFactory $formatters,
        private SecretScrubber $scrubber,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The log stream\'s id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $stream = OwnedStreams::find($context);
        $formatter = $this->formatters->for($stream->destination);
        $now = CarbonImmutable::now();

        $event = new SiemEvent(
            id: (string) Str::uuid(),
            occurredAt: $now->toDateTimeImmutable(),
            action: self::ACTION,
            category: EventCategory::Configuration,
            outcome: Outcome::Success,
            severity: Severity::Info,
            message: 'Test entry from '.MailText::brand().'. Your log stream is connected.',
            context: ['log_stream_id' => $stream->id, 'test' => true],
        );

        $error = null;

        try {
            $this->sink->send([$formatter->format($event)], new StreamTarget(
                name: $stream->name,
                endpoint: $stream->endpoint_url,
                options: [
                    'destination' => $stream->destination->value,
                    'auth' => $stream->auth->value,
                    // Decrypted in memory only, for the sink to build the auth header.
                    'secret' => $stream->secret,
                    'content_type' => $formatter->contentType(),
                    'gzip' => config('siem.http.gzip', false) === true,
                ],
            ));
        } catch (StreamDeliveryFailed $failed) {
            $error = $this->scrubber->scrub($failed->getMessage(), $stream->secret);
        }

        return ActionResult::item($stream, [
            'id' => $stream->id,
            'delivered' => $error === null,
            'error' => $error,
            'tested_at' => $now->toIso8601ZuluString(),
        ]);
    }
}
