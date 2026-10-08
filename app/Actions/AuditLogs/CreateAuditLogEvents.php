<?php

declare(strict_types=1);

namespace App\Actions\AuditLogs;

use App\Http\Resources\Environment\AuditLogEventResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AuditLogs\AuditLogIngest;
use App\Platform\AuditLogs\EventShape;

/**
 * Record audit events an app's own users caused — "Ada voided invoice 123" — for the
 * organizations (the app's customers) they happened in.
 *
 * A BATCH, of 1 to 100 events, each naming its own organization: the shape a backend that
 * buffers events flushes in, and one round trip instead of a hundred. All or nothing — one
 * bad event refuses the batch, with every problem named by its position
 * ({@see AuditLogIngest}). Send an `Idempotency-Key` with every call: a retry after a
 * timeout then records nothing twice, and the answer it gets back is the first one.
 *
 * Each event is checked against its action's schema when the action has one, and appended
 * to its organization's hash chain. Nothing is written to the platform's own audit trail
 * for it — these events ARE the record.
 */
#[AsAction(
    name: 'audit_logs.events.create',
    summary: 'Record 1–100 audit events your app\'s users caused, each for one of your organizations (customers); checked against the action\'s schema when it has one and appended to that organization\'s tamper-evident chain. Send an Idempotency-Key.',
    scope: 'audit_logs:write',
    danger: Danger::Write,
    schema: 'AuditLogEventBatch',
    tag: 'App audit logs',
    rest: ['POST', '/audit-logs/events'],
    status: 201,
    consoleGate: ConsoleGate::Administer,
)]
final readonly class CreateAuditLogEvents implements Action
{
    public const int MAX_BATCH = 100;

    public function __construct(private AuditLogIngest $ingest) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::list('events', Field::object('event', [
                Field::string('organization_id')->required()->max(64)->describe('The organization (your customer) the event happened in.'),
                Field::string('action')->required()->max(190)->describe('What happened, as dotted words: `invoice.voided`, `user.signed_in`.'),
                Field::string('occurred_at')->required()->format('date-time')->describe('When it happened: ISO 8601 with a time zone, to the millisecond.'),
                Field::object('actor', [
                    Field::string('id')->required()->max(190)->describe('Your id for whoever did it.'),
                    Field::string('type')->required()->max(100)->describe('What kind of actor: `user`, `api_key`, `system`.'),
                    Field::string('name')->nullable()->max(190)->describe('A name a person reads in the log.'),
                    Field::object('metadata', [])->nullable()->describe('Up to 50 names to strings, numbers, booleans or null.'),
                ])->required(),
                Field::list('targets', Field::object('target', [
                    Field::string('id')->required()->max(190),
                    Field::string('type')->required()->max(100)->describe('What kind of thing: `invoice`, `user`, `report`.'),
                    Field::string('name')->nullable()->max(190),
                    Field::object('metadata', [])->nullable(),
                ])->required())->max(EventShape::MAX_TARGETS)->describe('What it was done to.'),
                Field::object('context', [
                    Field::string('location')->nullable()->max(64)->describe('Where it came from — usually the client\'s IP address.'),
                    Field::string('user_agent')->nullable()->max(512),
                ]),
                Field::object('metadata', [])->nullable()->describe('Up to 50 names to strings, numbers, booleans or null; values up to 500 characters.'),
            ])->required())->required()->min(1)->max(self::MAX_BATCH)->describe('1–100 events, recorded all together or not at all.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        /** @var list<array<string, mixed>> $events */
        $events = array_values(array_filter($context->array('events'), is_array(...)));

        $stored = $this->ingest->record($events, $context->principal->confinedToOrganization());

        return ActionResult::item($stored, [
            'events' => array_map(AuditLogEventResource::from(...), $stored),
        ]);
    }
}
