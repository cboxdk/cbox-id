# Laravel Signals Client

Typed event, metric, login-evaluation and replay transport for Cbox Signals, plus a
transactional event outbox and a signature-verified signal inbox. PHP 8.4+ and
Laravel 12/13. This package is under implementation and has not been released.
Use the reviewed source checkout until a release is separately authorized.

## Configure

Install the package through Composer's path repository for local development.
Laravel discovers `SignalsServiceProvider`. Publish its configuration and migrations:

```sh
php artisan vendor:publish --tag=signals-config
php artisan vendor:publish --tag=signals-migrations
php artisan migrate
```

Set `SIGNALS_URL`, `SIGNALS_TOKEN` and `SIGNALS_TIMEOUT` according to `config/signals.php`.
A key selects one project/environment and its permitted sources. Never put keys in
client-side code. The public API uses HTTPS; isolated loopback tests may use HTTP.
The receiver checks project and environment against its own configuration.

## Events

Resolve `Cbox\Signals\Contracts\Signals` through the container:

```php
use Cbox\Signals\Contracts\Signals;
use Cbox\Signals\Data\Event;
use Cbox\Signals\Data\EventBatch;

$receipt = app(Signals::class)->events(
    new EventBatch('shop', [new Event(
        $occurrenceId,
        'shop.document_blocked',
        1,
        new DateTimeImmutable,
        ['customer' => $customerId, 'document' => $documentId],
    )]),
    $batchId,
);
```

Persist occurrence and batch identities before the first send. Retrying the same
identity must send identical bytes and timestamps; changed content produces 409.
A 202 receipt means durable acceptance, not completed detection. Read its operation
through `GET /v1/operations/{id}`. Keys need `write` and the specific source grant.
Only newly accepted occurrences consume the `signals.events` allowance.

When events accompany business writes, resolve `Contracts\EventOutbox` and call
`enqueue($batch, $batchId)` with a persisted UUID in the same database transaction. Run
`php artisan signals:dispatch-events` from the application's scheduler. The outbox keeps
an identical request across retries and marks it sent only after durable acceptance.
The outbox and the business write must use the same database connection.

## Metrics and replay

`metrics(MetricBatch $batch, string $key): Receipt` accepts typed `MetricSample`s.
Each sample has an occurrence ID, metric name, numeric value, unit, `MetricKind`,
observation time, `Entity` and named dimensions. Cumulative/delta counters also
carry interval boundaries when required by the engine. Empty dimensions serialize
as a JSON object. Preserve instance, deployment and restart dimensions so a new
process does not inherit the previous process's memory baseline.

`replay(ReplayRequest $request, string $key): Receipt` requires `manage`. Choose
explicit `from`, `to`, configuration revision, mapping version and detector ID.
The current contract permits a maximum seven-day historical window. Replay returns
an operation; inspect its result through the operation endpoint. It neither emits
live deliveries nor bills previously accepted samples again.

## Login evaluation

`evaluate(EvaluationRequest $request): Evaluation` requires `evaluate`. Persist one
`requestId` for the actual login attempt and retain it across transport retries.
Use server-observed device/session/IP context, not browser assertions. The only
current action is `auth.login`.

`evaluated`, `insufficient_data` and `degraded` are different states. A transport,
quota or malformed-response failure throws `SignalsFailure` with `errorCode` and
HTTP `status`. The caller owns its authentication policy and deadline; the SDK never
issues a session, bypasses MFA or interprets unavailable evidence as low risk.
A signal's expiry and revision are relevant to avoiding repeated challenges.

## Receive signed signals

Keep the exact raw request body. Resolve `Webhooks\VerifySignalWebhook`, pass the
`X-Cbox-Signature` header, a `Cbox\WebhookSignature\ValueObjects\SecretSet`, the
receiver's configured project/environment and the current Unix timestamp. It uses
the shared Cbox signature package, validates five-minute freshness and scope, and
returns a typed `SignalEnvelope`.

Pass that envelope to `Contracts\SignalInbox::accept()` before returning 2xx. The
inbox stores delivery identity/body hash and monotonically advances signal revision
in a transaction. An identical retry returns false; different bytes under the same
delivery identity produce 409. Older revisions never replace newer state.
`active($project, $environment)` excludes resolved/expired or time-expired signals.
Perform dependent local state changes in that same connection's transaction. Do not
interpret a duplicate result as a new action or create financial side effects.

Rotate signing keys with an overlap: configure old+new keys at the receiver, switch
the sender, verify delivery with the new key, then remove the old key. After removal,
old-key signatures fail. Never put signing secrets in logs or queue metadata.

## Contracts and verification

Canonical wire schemas and OpenAPI live in `cboxdk/signalsd/contracts`.
`tests/Contracts` carries exact schema/fixture copies for independent PHP validation
using Opis JSON Schema 2.6.0; Go validates the same fixtures with its independent
Draft 2020-12 implementation. Synchronize both copies when changing a contract.

```sh
composer qa
composer sbom
```

The app's `tests/Integration/sdk-metrics-roundtrip.py` runs this actual SDK against
isolated PostgreSQL/NATS/ClickHouse-backed engine services. The engine's
`TestActualPHPReceiverRetryRotationAndExpiry` starts the actual PHP inbox service,
verifies TLS, commit-before-failed-ack retry, key overlap/removal, duplicate and
out-of-order delivery, expiry and foreign-scope rejection. HTTP fakes in unit tests
are reserved for deterministic failure cases; they are not integration evidence.

Do not enable live authentication enforcement or production payments from these
local proofs. See the application's `BUILD-STATUS.md` for remaining pilot gates.

## Test doubles

`Testing\FakeSignals` implements the same `Contracts\Signals` interface. Bind it
explicitly in a test and queue each expected result with `queueReceipt('events',
$receipt)`, `queueReceipt('metrics', $receipt)`, `queueReceipt('replay', $receipt)` or
`queueEvaluation($evaluation)`. Each queue also accepts an exception to simulate
failure. Missing responses throw `LogicException`; the fake never invents success.
Inspect `eventCalls`, `metricCalls`, `replayCalls` and `evaluationCalls` to verify
payloads and stable retry keys. Production service providers never select this fake.

### Follow accepted work and erase an entity

`Signals::operation($receipt->operationId)` returns an `Operation` with an
`OperationState` (`Accepted`, `Completed`, `Failed`), accepted count, nullable error
code and typed isolated `ReplayResult`. Acceptance is not completion. Poll with a
bounded deadline/backoff appropriate to the caller; a failed operation is not a
successful zero-result replay. Replay responses declaring live deliveries or
billable events are rejected as malformed.

`Signals::erase(new Entity(...))` requires a `manage` key. Retry the same entity to
obtain the same request ID; use `Signals::erasure($id)` to follow `Pending`,
`PendingHistory`, and (when configuration acknowledgement is required)
`PendingConfiguration`. Only `Completed` confirms that operational and analytical
cleanup and all required configuration acknowledgements have finished. Usage/deduplication records survive, and the
entity tombstone refuses new ingestion. This API does not delete a whole project
or retroactively remove encrypted backup copies; follow the service's recovery
and retention procedure for those.

All read/status responses use the same bounded authenticated transport and refuse
redirects. Malformed states and invalid RFC3339 timestamps raise `SignalsFailure`
instead of returning invented status or leaking a date-parser exception. Test
fakes require explicit `queueOperation()` and `queueErasure('erase'|'erasure', …)`
responses; exhausted queues throw.

### Read signals, evidence and source health

Use a key with `read` scope. All results remain confined to that key's project and
environment; these methods cannot select another tenant.

```php
use Cbox\Signals\Data\SignalFilter;

$page = $signals->signals(new SignalFilter(status: 'active', severity: 'high'));
foreach ($page->data as $finding) {
    $detail = $signals->signal($finding->id);
    // $detail->signal->evidence contains typed measures and occurrence references.
    // $detail->revisions and ->deliveries contain bounded history, never secrets.
}
if ($page->nextCursor !== null) {
    $next = $signals->signals(new SignalFilter(after: $page->nextCursor, status: 'active', severity: 'high'));
}
$detectors = $signals->detectors();
$connections = $signals->connections();
```

`SignalPage` contains at most 50 `SignalRecord` values and an opaque nullable
cursor. Preserve the same filters when following it. Pagination is explicit, so a
caller controls its own work/deadline. `SignalDetail` reports `historyLimit` (100),
revision history and delivery metadata. It is not an unbounded audit export.
Metric findings have `mappingVersion=0` because no event mapping was applied.

`DetectorState` retains per-quality counts, entity count and nullable data time.
`ConnectionState` reports actual imports, budget, last attempt/success/event times,
nullable error and pending operation. No checkpoint rows means no imported source
history; an empty list does not imply that configured sources are healthy. Missing
samples, degraded quality and expired signals must remain visible to consumers.
Read methods never change payments, subscriptions, sessions or detector state.

Test fakes expose `queueSignals`, `queueSignal`, `queueDetectors` and
`queueConnections`, corresponding call logs, exception queues and exhaustion
errors. `tests/ReadTest.php` verifies pagination, historical evidence, incomplete
states, malformed responses and access refusal. The real SDK acceptance additionally
reads persisted metric findings/details/status before replay and entity erasure.

`$signals->retention()` reads the credential-scoped policy and operational/history
cleanup watermarks. `pending_history` means ClickHouse cleanup is not yet confirmed;
a configured number of retention days alone is not evidence of completed cleanup.
Read access is required. The test fake requires `queueRetention($state)` or an
explicit exception; it never supplies a fabricated ready state.

Signal detail exposes the latest retained normalized activity for its subject as
`$detail->recentObservations`. Each entry carries the source, event ID, type and
event time. Use the signal's own evidence for its historical decision.
